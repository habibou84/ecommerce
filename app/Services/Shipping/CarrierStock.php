<?php

namespace App\Services\Shipping;

use App\Enums\StockLocationType;
use App\Exceptions\BusinessRuleException;
use App\Models\CarrierConnection;
use App\Models\CarrierProductMapping;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockLocation;
use Illuminate\Support\Facades\DB;

/**
 * Stock déposé dans l'entrepôt d'un transporteur (docs/PLAN.md §5.5) : l'entrepôt devient un emplacement de la
 * boutique, les produits sont rapprochés par référence (SKU) et le stock du transporteur, qui fait foi, est
 * comparé au nôtre.
 */
class CarrierStock
{
    public function __construct(private readonly CarrierConnections $connections) {}

    public function linkHub(CarrierConnection $connection, string $remoteHubId): StockLocation
    {
        $hub = collect($this->connections->driver($connection)->hubs($connection))->firstWhere('remote_id', $remoteHubId)
            ?? throw new BusinessRuleException('Entrepôt introuvable chez '.$connection->name.'.', 'remote_hub_id');

        return StockLocation::forStore($connection->store_id)->firstOrCreate(
            ['store_id' => $connection->store_id, 'carrier_connection_id' => $connection->id, 'remote_hub_id' => $remoteHubId],
            [
                'name' => mb_substr($connection->name.' — '.$hub['name'], 0, 120),
                'type' => StockLocationType::CarrierHub,
                'is_pickup_point' => false,
                'fulfills_online_orders' => true,
                'priority' => 50,
                'is_active' => true,
            ],
        );
    }

    /**
     * Rapprochement des produits du transporteur avec les variantes (même SKU) et comparaison des stocks.
     *
     * @return array{hubs: list<array{remote_id: string, name: string, location_id: ?int}>, products: list<array<string, mixed>>}
     */
    public function sync(CarrierConnection $connection): array
    {
        if (! $connection->allows('stock:read')) {
            throw new BusinessRuleException('La clé de '.$connection->name.' n\'a pas la portée « stock:read » : créez une clé avec cette portée.');
        }

        $driver = $this->connections->driver($connection);
        $hubs = $driver->hubs($connection);
        $remote = $driver->products($connection);
        $locations = StockLocation::forStore($connection->store_id)->where('carrier_connection_id', $connection->id)->pluck('id', 'remote_hub_id');
        $variants = ProductVariant::forStore($connection->store_id)->whereNotNull('sku')->with('product')->get()
            ->keyBy(fn ($v) => mb_strtolower(trim($v->sku)));
        $local = StockLevel::forStore($connection->store_id)->whereIn('location_id', $locations->values())->get()
            ->groupBy('variant_id')->map(fn ($levels) => (int) $levels->sum('on_hand'));

        $rows = DB::transaction(function () use ($connection, $remote, $variants, $locations, $local) {
            $rows = [];
            foreach ($remote as $product) {
                $variant = $product['sku'] ? $variants->get(mb_strtolower(trim($product['sku']))) : null;
                // Seuls les entrepôts reliés à un emplacement de la boutique comptent
                $remoteOnHand = (int) collect($product['hubs'])->only($locations->keys()->map(fn ($k) => (string) $k)->all())->sum('on_hand');

                if ($variant) {
                    CarrierProductMapping::updateOrCreate(
                        ['connection_id' => $connection->id, 'variant_id' => $variant->id],
                        ['remote_product_id' => $product['remote_id'], 'remote_sku' => $product['sku'], 'remote_on_hand' => $remoteOnHand, 'synced_at' => now()],
                    );
                }

                $localOnHand = $variant ? ($local[$variant->id] ?? 0) : null;
                $rows[] = [
                    'remote_id' => $product['remote_id'],
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'remote_on_hand' => $remoteOnHand,
                    'variant_id' => $variant?->id,
                    'variant_label' => $variant?->fullTitle(),
                    'local_on_hand' => $localOnHand,
                    'difference' => $localOnHand === null ? null : $remoteOnHand - $localOnHand,
                ];
            }

            return $rows;
        });

        return [
            'hubs' => array_map(fn ($h) => $h + ['location_id' => $locations[$h['remote_id']] ?? null], $hubs),
            'products' => $rows,
        ];
    }
}
