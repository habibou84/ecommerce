<?php

namespace App\Services\Stock;

use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lectures agrégées du stock (listes, alertes). Une variante est « basse » quand son disponible, tous
 * emplacements actifs confondus, est positif et au plus égal au seuil du produit (sinon celui de la boutique),
 * « épuisée » quand il est nul ou négatif. Seuls les produits suivis en stock et les variantes actives comptent.
 */
class StockLevels
{
    /**
     * Disponible par variante.
     */
    public function variantAvailability(Store $store): Builder
    {
        return DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('stock_levels as l', 'l.variant_id', '=', 'v.id')
            ->leftJoin('stock_locations as loc', fn ($j) => $j->on('loc.id', '=', 'l.location_id')->where('loc.is_active', true))
            ->where('v.store_id', $store->id)
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('v.is_active', true)
            ->where('p.track_inventory', true)
            ->groupBy('v.id', 'v.product_id', 'p.low_stock_threshold')
            ->select('v.id as variant_id', 'v.product_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN loc.id IS NOT NULL THEN l.on_hand END), 0) AS on_hand')
            ->selectRaw('COALESCE(SUM(CASE WHEN loc.id IS NOT NULL THEN l.on_hand - l.reserved END), 0) AS available')
            ->selectRaw('COALESCE(p.low_stock_threshold, ?) AS threshold', [$store->low_stock_threshold]);
    }

    /**
     * @return list<int>
     */
    public function productIdsWithStockIssue(Store $store, string $filter): array
    {
        return DB::query()->fromSub($this->variantAvailability($store), 'a')
            ->when($filter === 'out', fn ($q) => $q->where('available', '<=', 0))
            ->when($filter === 'low', fn ($q) => $q->where('available', '>', 0)->whereColumn('available', '<=', 'threshold'))
            ->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array{on_hand: int, available: int, low: int}>
     */
    public function totalsByProduct(Store $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::query()->fromSub($this->variantAvailability($store)->whereIn('v.product_id', $productIds), 'a')
            ->groupBy('product_id')
            ->select('product_id')
            ->selectRaw('SUM(on_hand) AS on_hand, SUM(available) AS available')
            ->selectRaw('SUM(CASE WHEN available <= threshold THEN 1 ELSE 0 END) AS low')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => ['on_hand' => (int) $row->on_hand, 'available' => (int) $row->available, 'low' => (int) $row->low]])
            ->all();
    }

    /**
     * Compteurs de l'écran Stock et de l'accueil.
     *
     * @return array{low: int, out: int, units: int, value: int}
     */
    public function summary(Store $store): array
    {
        $row = DB::query()->fromSub($this->variantAvailability($store)->addSelect('v.cost_price')->groupBy('v.cost_price'), 'a')
            ->selectRaw('SUM(CASE WHEN available > 0 AND available <= threshold THEN 1 ELSE 0 END) AS low')
            ->selectRaw('SUM(CASE WHEN available <= 0 THEN 1 ELSE 0 END) AS out')
            ->selectRaw('COALESCE(SUM(on_hand), 0) AS units')
            ->selectRaw('COALESCE(SUM(GREATEST(on_hand, 0) * COALESCE(cost_price, 0)), 0) AS value')
            ->first();

        return ['low' => (int) $row->low, 'out' => (int) $row->out, 'units' => (int) $row->units, 'value' => (int) $row->value];
    }
}
