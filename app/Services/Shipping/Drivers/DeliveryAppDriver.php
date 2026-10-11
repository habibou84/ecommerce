<?php

namespace App\Services\Shipping\Drivers;

use App\Models\CarrierConnection;
use App\Models\Shipment;
use App\Services\Shipping\CarrierException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Entreprise de livraison qui utilise Delivery App : API publique /api/public/v1 avec la clé du marchand.
 */
class DeliveryAppDriver implements CarrierDriver
{
    public const CLIENT_APP = 'jibiat-marchand';

    /** Portées demandées à la clé du marchand */
    public const SCOPES = ['orders:read', 'orders:write', 'webhooks:write', 'payouts:read', 'stock:read'];

    public function account(CarrierConnection $connection): array
    {
        return $this->send($connection, fn (PendingRequest $http) => $http->get('me'))->json('data');
    }

    public function zones(CarrierConnection $connection): array
    {
        $zones = $this->send($connection, fn (PendingRequest $http) => $http->get('zones'))->json('data') ?? [];
        $names = collect($zones)->pluck('name', 'id');

        return array_map(fn (array $z) => [
            'remote_id' => (string) $z['id'],
            'name' => (string) $z['name'],
            'parent_name' => $z['parent_id'] ? ($names[$z['parent_id']] ?? null) : null,
            'is_shipping' => (bool) ($z['is_shipping'] ?? false),
            'is_active' => (bool) ($z['is_active'] ?? true),
        ], $zones);
    }

    public function hubs(CarrierConnection $connection): array
    {
        return array_map(fn (array $h) => ['remote_id' => (string) $h['id'], 'name' => (string) $h['name']],
            $this->send($connection, fn (PendingRequest $http) => $http->get('hubs'))->json('data') ?? []);
    }

    public function products(CarrierConnection $connection): array
    {
        $products = [];
        for ($page = 1; $page <= 50; $page++) {
            $response = $this->send($connection, fn (PendingRequest $http) => $http->get('products', ['per_page' => 200, 'page' => $page]));
            foreach ($response->json('data') ?? [] as $p) {
                $hubs = [];
                foreach ($p['levels'] ?? [] as $level) {
                    if (($level['hub_id'] ?? null) !== null) {
                        $key = (string) $level['hub_id'];
                        $hubs[$key] = [
                            'on_hand' => ($hubs[$key]['on_hand'] ?? 0) + (int) $level['on_hand'],
                            'available' => ($hubs[$key]['available'] ?? 0) + (int) $level['available'],
                        ];
                    }
                }
                $products[] = ['remote_id' => (string) $p['id'], 'sku' => $p['sku'] ?? null, 'name' => (string) $p['name'], 'hubs' => $hubs];
            }
            if ($page >= (int) $response->json('meta.last_page', 1)) {
                break;
            }
        }

        return $products;
    }

    public function quote(CarrierConnection $connection, array $draft): ?int
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http->post('quotes', array_filter([
            'delivery_zone_id' => (int) $draft['zone_id'],
            'pickup_zone_id' => isset($draft['pickup_zone_id']) ? (int) $draft['pickup_zone_id'] : null,
            'is_fragile' => $draft['is_fragile'] ?? false,
            'weight_kg' => $draft['weight_kg'] ?? null,
        ], fn ($v) => $v !== null)));

        return $response->json('data.total');
    }

    public function create(CarrierConnection $connection, Shipment $shipment, array $payload): RemoteShipment
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $shipment->idempotency_key])
            ->post('orders', $payload));

        return RemoteShipment::fromDeliveryApp($response->json('data'));
    }

    public function cancel(CarrierConnection $connection, Shipment $shipment, ?string $reason): RemoteShipment
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http
            ->post('orders/'.rawurlencode($shipment->carrier_tracking_code).'/cancel', ['reason' => $reason]));

        return RemoteShipment::fromDeliveryApp($response->json('data'));
    }

    public function fetch(CarrierConnection $connection, Shipment $shipment): RemoteShipment
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http->get('orders/'.rawurlencode($shipment->carrier_tracking_code)));

        return RemoteShipment::fromDeliveryApp($response->json('data'));
    }

    public function changedSince(CarrierConnection $connection, \DateTimeInterface $since): array
    {
        $changed = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = $this->send($connection, fn (PendingRequest $http) => $http->get('orders', [
                'updated_since' => $since->format(DATE_ATOM), 'per_page' => 100, 'page' => $page,
            ]));
            foreach ($response->json('data') ?? [] as $order) {
                $changed[] = RemoteShipment::fromDeliveryApp($order);
            }
            if ($page >= (int) $response->json('meta.last_page', 1)) {
                break;
            }
        }

        return $changed;
    }

    public function registerWebhook(CarrierConnection $connection, string $url, array $events): array
    {
        $response = $this->send($connection, fn (PendingRequest $http) => $http->post('webhooks', [
            'url' => $url,
            'events' => $events,
            'description' => 'Jibiat Marchand',
        ]));

        return ['id' => (string) $response->json('data.id'), 'secret' => (string) $response->json('secret')];
    }

    public function removeWebhook(CarrierConnection $connection): void
    {
        if ($connection->remote_webhook_id === null) {
            return;
        }

        $this->send($connection, fn (PendingRequest $http) => $http->delete('webhooks/'.rawurlencode($connection->remote_webhook_id)), allowMissing: true);
    }

    public function payout(CarrierConnection $connection, string $reference): array
    {
        $data = $this->send($connection, fn (PendingRequest $http) => $http->get('payouts/'.rawurlencode($reference)))->json('data');

        // Une ligne par course : encaissements (positifs) et frais retenus (négatifs)
        $lines = collect($data['entries'] ?? [])->filter(fn ($e) => isset($e['order']['tracking_code']))
            ->groupBy(fn ($e) => $e['order']['tracking_code'])
            ->map(fn ($entries, $code) => [
                'tracking_code' => (string) $code,
                'collected' => (int) $entries->where('amount', '>', 0)->sum('amount'),
                'fees' => (int) -$entries->where('amount', '<', 0)->sum('amount'),
            ])->values()->all();

        return [
            'remote_id' => (string) $data['reference'],
            'amount' => (int) $data['net_amount'],
            'total_collected' => (int) ($data['total_collected'] ?? 0),
            'total_fees' => (int) ($data['total_fees'] ?? 0),
            'method' => $data['method'] ?? null,
            'transaction_ref' => $data['transaction_ref'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'lines' => $lines,
            'raw' => $data,
        ];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(CarrierConnection $connection, callable $call, bool $allowMissing = false): Response
    {
        $http = Http::baseUrl(rtrim((string) $connection->base_url, '/').'/')
            ->withToken((string) $connection->api_key)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Client-App' => self::CLIENT_APP])
            ->timeout(15)
            ->connectTimeout(5)
            ->withoutRedirecting();

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            throw CarrierException::unreachable($connection->name);
        }

        if ($response->successful() || ($allowMissing && $response->status() === 404)) {
            return $response;
        }

        $message = match ($response->status()) {
            401 => 'Clé API refusée par '.$connection->name.' : vérifiez-la ou créez-en une nouvelle.',
            403 => $response->json('message') ?? 'Accès refusé par '.$connection->name.'.',
            404 => 'Introuvable chez '.$connection->name.'.',
            422 => $this->firstError($response) ?? 'Demande refusée par '.$connection->name.'.',
            429 => $connection->name.' reçoit trop de demandes : réessayez dans une minute.',
            default => $response->serverError() ? CarrierException::unreachable($connection->name)->getMessage() : ($response->json('message') ?? 'Erreur '.$response->status()),
        };

        throw new CarrierException($message);
    }

    private function firstError(Response $response): ?string
    {
        $errors = $response->json('errors');
        $first = is_array($errors) ? collect($errors)->flatten()->first() : null;

        return is_string($first) ? $first : $response->json('message');
    }
}
