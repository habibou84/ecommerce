<?php

namespace App\Services\Shipping;

use App\Models\CarrierConnection;
use App\Models\CarrierPayout;
use App\Models\CarrierWebhookReceipt;
use App\Models\Shipment;
use App\Services\Shipping\Drivers\RemoteShipment;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Jibiat\Socle\Webhooks\WebhookSignature;
use Throwable;

/**
 * Événements des transporteurs : webhooks signés (dédoublonnés sur X-Webhook-Id) et synchronisation périodique
 * qui rattrape un webhook perdu.
 */
class CarrierEvents
{
    public function __construct(
        private readonly ShipmentDesk $shipments,
        private readonly CarrierConnections $connections,
        private readonly PayoutReconciler $payouts,
    ) {}

    /**
     * @return int code HTTP de la réponse au transporteur
     */
    public function receive(CarrierConnection $connection, Request $request): int
    {
        app(Tenancy::class)->setStore($connection->store);
        $body = $request->getContent();
        if (! $connection->webhook_secret || ! WebhookSignature::verify($connection->webhook_secret, $request->header('X-Webhook-Timestamp'), $body, $request->header('X-Webhook-Signature'))) {
            return 401;
        }

        $payload = json_decode($body, true);
        $webhookId = (string) ($request->header('X-Webhook-Id') ?? ($payload['id'] ?? ''));
        $event = (string) ($request->header('X-Webhook-Event') ?? ($payload['event'] ?? ''));
        if (! is_array($payload) || $webhookId === '') {
            return 400;
        }

        $receipt = CarrierWebhookReceipt::firstOrCreate(
            ['connection_id' => $connection->id, 'webhook_id' => $webhookId],
            ['event' => mb_substr($event, 0, 40), 'payload' => $payload, 'received_at' => now()],
        );
        if (! $receipt->wasRecentlyCreated && $receipt->processed_at !== null) {
            return 200; // déjà traité
        }

        try {
            $this->handle($connection, $event, $payload['data'] ?? [], $payload['created_at'] ?? null);
            $receipt->forceFill(['processed_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $receipt->forceFill(['error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return 500; // le transporteur réessaiera
        }

        return 200;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(CarrierConnection $connection, string $event, array $data, ?string $occurredAt = null): void
    {
        match ($event) {
            'order.created', 'order.status_changed', 'order.incident', 'order.updated' => isset($data['order']['tracking_code'])
                ? $this->applyOrder($connection, RemoteShipment::fromDeliveryApp($data['order'], $occurredAt), 'carrier_webhook', $data['change']['note'] ?? null)
                : null,
            'payout.paid' => isset($data['payout']['reference']) ? $this->payouts->import($connection, (string) $data['payout']['reference']) : null,
            default => null, // ping, stock.low : rien à faire ici
        };
    }

    /**
     * Rattrapage : courses modifiées depuis la dernière synchronisation (moins une marge).
     */
    public function sync(CarrierConnection $connection): int
    {
        // Les modèles de la boutique sont lus et créés dans son périmètre (commande artisan : aucune boutique)
        app(Tenancy::class)->setStore($connection->store);
        $since = ($connection->last_synced_at ?? now()->subDay())->copy()->subMinutes(5);
        $started = now();

        try {
            $changed = $this->connections->driver($connection)->changedSince($connection, $since);
        } catch (CarrierException $e) {
            $connection->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 250)])->save();

            return 0;
        }

        $this->connections->refreshWebhook($connection);

        $applied = 0;
        foreach ($changed as $remote) {
            $applied += $this->applyOrder($connection, $remote, 'carrier_sync') ? 1 : 0;
        }
        $this->syncPayouts($connection);
        $connection->forceFill(['last_synced_at' => $started, 'last_error' => null])->save();

        return $applied;
    }

    /**
     * Reversements payés pas encore reçus par webhook : importés et rapprochés, pour que le solde à recevoir
     * ne compte pas des courses déjà reversées.
     */
    private function syncPayouts(CarrierConnection $connection): void
    {
        if (! $connection->allows('payouts:read')) {
            return;
        }

        try {
            $payouts = CarrierPayout::forStore($connection->store_id)->where('connection_id', $connection->id);
            $known = (clone $payouts)->pluck('remote_id')->all();
            // Depuis le dernier reversement reçu (marge de deux jours), pas seulement les derniers de la liste
            $lastPaidAt = (clone $payouts)->max('paid_at');
            $since = $lastPaidAt ? Carbon::parse($lastPaidAt)->subDays(2) : null;
            foreach (array_diff($this->connections->driver($connection)->paidPayouts($connection, $since), $known) as $reference) {
                $this->payouts->import($connection, $reference);
            }
        } catch (CarrierException) {
            // Transporteur injoignable : nouvel essai à la prochaine synchronisation
        }
    }

    private function applyOrder(CarrierConnection $connection, RemoteShipment $remote, string $source, ?string $note = null): bool
    {
        $shipment = Shipment::forStore($connection->store_id)
            ->where('carrier_connection_id', $connection->id)
            ->where('carrier_tracking_code', $remote->trackingCode)
            ->first();

        if ($shipment === null) {
            return false; // course créée hors de la boutique
        }

        $this->shipments->apply($shipment, $remote, $source, $note);

        return true;
    }
}
