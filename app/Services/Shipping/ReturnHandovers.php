<?php

namespace App\Services\Shipping;

use App\Enums\Permission;
use App\Models\CarrierConnection;
use App\Models\CarrierReturnHandover;
use App\Models\Shipment;
use App\Models\Store;
use App\Notifications\ReturnHandoverNotice;
use App\Services\Accounts\TeamNotifier;
use App\Services\Shipping\Drivers\DeliveryAppDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Remise des retours par un livreur de Jibiat (contrat socle 1.14) : le livreur est chez le marchand avec les
 * colis qu'il rapporte ; le marchand confirme ici (« Tout reçu ») ou dit qu'il en manque (l'agence tranche).
 * La remise arrive par le webhook return_handover.updated, et la liste est relue chez l'entreprise à l'ouverture.
 * Le stock ne revient qu'avec le statut « Retournée » des courses, donc après la confirmation.
 */
class ReturnHandovers
{
    public function __construct(private readonly CarrierConnections $connections, private readonly TeamNotifier $team) {}

    /**
     * Copie (ou met à jour) la remise reçue de l'entreprise ; prévient l'équipe quand un livreur arrive.
     *
     * @param  array<string, mixed>  $data  remise au format du contrat (return-handover)
     */
    public function apply(CarrierConnection $connection, array $data): ?CarrierReturnHandover
    {
        $reference = (string) ($data['reference'] ?? '');
        if ($reference === '' || ! isset($data['status'])) {
            return null;
        }

        $handover = CarrierReturnHandover::withoutGlobalScopes()->firstOrNew(['connection_id' => $connection->id, 'reference' => $reference]);

        $handover->fill([
            'store_id' => $connection->store_id,
            'status' => (string) $data['status'],
            'courier_name' => $data['courier']['name'] ?? null,
            'received_by_name' => $data['received_by_name'] ?? null,
            'parcels' => collect($data['orders'] ?? [])->map(fn ($o) => [
                'tracking_code' => (string) ($o['tracking_code'] ?? ''),
                'recipient_name' => $o['recipient_name'] ?? null,
                'status' => $o['status'] ?? null,
            ])->values()->all(),
            'expires_at' => $this->date($data['expires_at'] ?? null),
            'handed_at' => $this->date($data['handed_at'] ?? null),
            'confirmed_at' => $this->date($data['confirmed_at'] ?? null),
            'confirmed_via' => $data['confirmed_via'] ?? null,
            'disputed_at' => $this->date($data['disputed_at'] ?? null),
        ])->save();

        // Livreur arrivé, ou colis laissés sans confirmation : une seule fois par remise
        if (in_array($handover->status, ['pending', 'unconfirmed'], true) && ($handover->wasRecentlyCreated || $handover->wasChanged('status'))) {
            $this->notify($connection, $handover);
        }

        return $handover;
    }

    /**
     * Remises à confirmer de la boutique, relues chez chaque entreprise de Jibiat connectée. Une remise qui n'est
     * plus listée (confirmée par WhatsApp, annulée…) n'est plus proposée.
     *
     * @return Collection<int, CarrierReturnHandover>
     */
    public function open(Store $store, bool $refresh = true): Collection
    {
        if ($refresh) {
            CarrierConnection::forStore($store->id)->where('driver', CarrierConnection::DELIVERY_APP)->get()
                ->filter(fn (CarrierConnection $c) => $c->isActive())
                ->each(fn (CarrierConnection $c) => $this->refresh($c));
        }

        return CarrierReturnHandover::forStore($store->id)->whereIn('status', CarrierReturnHandover::OPEN)
            ->with('connection:id,name')->latest('id')->get();
    }

    /**
     * « Tout reçu » ou « Il en manque », envoyé à l'entreprise ; sa réponse met la remise à jour.
     */
    public function answer(CarrierReturnHandover $handover, bool $allReceived): CarrierReturnHandover
    {
        $connection = $handover->connection;
        $data = $this->driver($connection)->answerReturnHandover($connection, $handover->reference, $allReceived);

        return $this->apply($connection, $data) ?? $handover;
    }

    /**
     * Colis de la remise, avec la commande de la boutique quand la course vient d'ici.
     *
     * @return list<array<string, mixed>>
     */
    public function parcels(CarrierReturnHandover $handover): array
    {
        $codes = collect($handover->parcels)->pluck('tracking_code')->filter()->all();
        $shipments = Shipment::forStore($handover->store_id)->where('carrier_connection_id', $handover->connection_id)
            ->whereIn('carrier_tracking_code', $codes)->with('order.store')->get()->keyBy('carrier_tracking_code');

        return collect($handover->parcels)->map(function (array $p) use ($shipments) {
            $order = $shipments->get($p['tracking_code'])?->order;

            return [...$p, 'order_id' => $order?->id, 'order_reference' => $order?->reference(), 'customer_name' => $order?->customer_name];
        })->values()->all();
    }

    private function refresh(CarrierConnection $connection): void
    {
        try {
            $remote = $this->driver($connection)->returnHandovers($connection);
        } catch (CarrierException) {
            return; // entreprise injoignable : on garde ce qu'on sait
        }

        $seen = [];
        foreach ($remote as $data) {
            $seen[] = $this->apply($connection, (array) $data)?->id;
        }

        CarrierReturnHandover::withoutGlobalScopes()->where('connection_id', $connection->id)
            ->whereIn('status', CarrierReturnHandover::OPEN)->whereNotIn('id', array_filter($seen))
            ->update(['status' => 'closed']);
    }

    private function notify(CarrierConnection $connection, CarrierReturnHandover $handover): void
    {
        $count = count($handover->parcels);
        $courier = $handover->courier_name ?: 'Le livreur';
        $title = $handover->status === 'unconfirmed'
            ? "Colis retournés laissés à {$handover->received_by_name}"
            : "{$courier} vous rapporte ".($count > 1 ? "{$count} colis" : 'un colis');
        $body = "{$connection->name}, remise {$handover->reference} : vérifiez puis touchez « Tout reçu » ou « Il en manque ».";

        $store = Store::find($connection->store_id);
        if ($store !== null) {
            $this->team->notify($store, Permission::ShipmentsManage->value, new ReturnHandoverNotice($store->id, $title, $body));
        }
    }

    private function driver(CarrierConnection $connection): DeliveryAppDriver
    {
        $driver = $this->connections->driver($connection);
        if (! $driver instanceof DeliveryAppDriver) {
            throw new CarrierException('Ce livreur ne gère pas la remise des retours.');
        }

        return $driver;
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
