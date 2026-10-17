<?php

namespace App\Services\Reports;

use App\Enums\OrderSource;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'une boutique sur une période (commandes créées dans la période) : vue d'ensemble et marge,
 * ventilations par canal, produit, zone, livreur, transporteur et promotion.
 */
class StoreStats
{
    /**
     * @return array<string, mixed>
     */
    public function report(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'overview' => $overview = $this->overview($store, $from, $to),
            'profit' => $this->profit($store, $from, $to, $overview['net_margin']),
            'by_source' => $this->bySource($store, $from, $to),
            'by_product' => $this->byProduct($store, $from, $to),
            'by_zone' => $this->byZone($store, $from, $to),
            'by_courier' => $this->byShipper($store, $from, $to, 'courier_id', 'couriers', 'name'),
            'by_carrier' => $this->byShipper($store, $from, $to, 'carrier_connection_id', 'carrier_connections', 'name'),
            'by_promotion' => $this->byPromotion($store, $from, $to),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    public function overview(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->orders($store, $from, $to)->selectRaw("
            COUNT(*) AS orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN status = 'pending_confirmation' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS delivered,
            SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned,
            SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) AS revenue,
            SUM(CASE WHEN status IN ('confirmed', 'processing', 'shipped') THEN total ELSE 0 END) AS in_progress,
            AVG(CASE WHEN status != 'cancelled' THEN total END) AS avg_basket,
            SUM(CASE WHEN status = 'completed' THEN discount_total ELSE 0 END) AS discounts,
            SUM(CASE WHEN status = 'completed' THEN shipping_fee ELSE 0 END) AS shipping_income,
            SUM(CASE WHEN status = 'completed' AND cost_total IS NOT NULL THEN subtotal - discount_total - cost_total ELSE 0 END) AS product_margin,
            SUM(CASE WHEN status = 'completed' AND cost_total IS NULL THEN 1 ELSE 0 END) AS without_cost
        ")->first();

        $decided = (int) $row->orders - (int) $row->pending;
        $finished = (int) $row->delivered + (int) $row->returned;
        $shippingCosts = $this->shippingCosts($store, $from, $to);

        return [
            'orders' => (int) $row->orders,
            'cancelled' => (int) $row->cancelled,
            // Commandes décidées (hors « à confirmer ») et confirmées : taux recalculables sur plusieurs boutiques
            'decided' => $decided,
            'confirmed' => (int) $row->confirmed,
            'delivered' => (int) $row->delivered,
            'returned' => (int) $row->returned,
            'revenue' => (int) $row->revenue,
            'in_progress' => (int) $row->in_progress,
            'avg_basket' => $row->avg_basket !== null ? (int) round((float) $row->avg_basket) : null,
            'confirmation_rate' => $decided > 0 ? round((int) $row->confirmed / $decided * 100, 1) : null,
            'delivery_rate' => $finished > 0 ? round((int) $row->delivered / $finished * 100, 1) : null,
            'discounts' => (int) $row->discounts,
            'product_margin' => (int) $row->product_margin,
            'shipping_income' => (int) $row->shipping_income,
            'shipping_costs' => $shippingCosts,
            // Marge nette des commandes livrées : produits (prix − remise − coût d'achat) + frais facturés − coût des livraisons
            'net_margin' => (int) $row->product_margin + (int) $row->shipping_income - $shippingCosts,
            'without_cost' => (int) $row->without_cost,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bySource(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->orders($store, $from, $to)->groupBy('source')->selectRaw("source,
            COUNT(*) AS orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS delivered,
            SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) AS revenue")
            ->orderByDesc('revenue')->orderByDesc('orders')->get()
            ->map(fn ($r) => [
                'source' => $r->source,
                'label' => OrderSource::tryFrom($r->source)?->label() ?? $r->source,
                'orders' => (int) $r->orders,
                'delivered' => (int) $r->delivered,
                'cancelled' => (int) $r->cancelled,
                'revenue' => (int) $r->revenue,
                'delivery_rate' => $this->rate((int) $r->delivered, (int) $r->delivered + (int) $r->returned),
            ])->all();
    }

    /**
     * Produits et variantes livrés : quantités, ventes (remises déduites), marge.
     *
     * @return list<array<string, mixed>>
     */
    private function byProduct(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->whereBetween('orders.created_at', [$from, $to])->where('orders.status', 'completed')
            ->groupBy('order_items.product_id', 'order_items.title', 'order_items.variant_title')
            ->selectRaw('order_items.product_id, order_items.title, order_items.variant_title,
                SUM(order_items.quantity) AS quantity,
                SUM(order_items.total - order_items.discount_amount) AS revenue,
                SUM(CASE WHEN order_items.unit_cost IS NULL THEN NULL ELSE order_items.unit_cost * order_items.quantity END) AS cost,
                COUNT(order_items.unit_cost) = COUNT(*) AS has_cost')
            ->orderByDesc('revenue')->limit(50)->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'title' => $r->title,
                'variant_title' => $r->variant_title,
                'quantity' => (int) $r->quantity,
                'revenue' => (int) $r->revenue,
                'margin' => $r->has_cost ? (int) $r->revenue - (int) $r->cost : null,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byZone(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->orders($store, $from, $to)->leftJoin('delivery_zones', 'delivery_zones.id', '=', 'orders.delivery_zone_id')
            ->where('orders.delivery_method', 'delivery')
            ->groupBy('orders.delivery_zone_id', 'delivery_zones.name')
            ->selectRaw("orders.delivery_zone_id AS zone_id, delivery_zones.name,
                COUNT(*) AS orders,
                SUM(CASE WHEN orders.status = 'completed' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN orders.status = 'returned' THEN 1 ELSE 0 END) AS returned,
                SUM(CASE WHEN orders.status = 'completed' THEN orders.total ELSE 0 END) AS revenue,
                AVG(orders.shipping_fee) AS avg_fee")
            ->orderByDesc('orders')->get()
            ->map(fn ($r) => [
                'zone_id' => $r->zone_id,
                'name' => $r->name ?? 'Hors zone',
                'orders' => (int) $r->orders,
                'delivered' => (int) $r->delivered,
                'returned' => (int) $r->returned,
                'revenue' => (int) $r->revenue,
                'delivery_rate' => $this->rate((int) $r->delivered, (int) $r->delivered + (int) $r->returned),
                'avg_fee' => $r->avg_fee !== null ? (int) round((float) $r->avg_fee) : null,
            ])->all();
    }

    /**
     * Livreurs ou transporteurs : courses, réussite, délai moyen, coût moyen.
     *
     * @return list<array<string, mixed>>
     */
    private function byShipper(Store $store, CarbonImmutable $from, CarbonImmutable $to, string $column, string $table, string $nameColumn): array
    {
        $cost = $column === 'courier_id'
            ? '(SELECT SUM(e.amount) FROM courier_earnings e WHERE e.shipment_id = shipments.id AND e.type IN (\'delivery\', \'failed_attempt\'))'
            : 'shipments.carrier_fee';

        return DB::table('shipments')->join($table, "{$table}.id", '=', "shipments.{$column}")
            ->where('shipments.store_id', $store->id)->whereBetween('shipments.created_at', [$from, $to])->where('shipments.status', '!=', 'cancelled')
            ->groupBy("shipments.{$column}", "{$table}.{$nameColumn}")
            ->selectRaw("shipments.{$column} AS id, {$table}.{$nameColumn} AS name,
                COUNT(*) AS shipments,
                SUM(CASE WHEN shipments.status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN shipments.status IN ('returned', 'lost') THEN 1 ELSE 0 END) AS failed,
                AVG(CASE WHEN shipments.status = 'delivered' THEN EXTRACT(EPOCH FROM (shipments.delivered_at - shipments.requested_at)) / 3600 END) AS hours,
                AVG({$cost}) AS avg_cost,
                SUM(COALESCE(shipments.collected_amount, 0)) AS collected")
            ->orderByDesc('shipments')->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'shipments' => (int) $r->shipments,
                'delivered' => (int) $r->delivered,
                'success_rate' => $this->rate((int) $r->delivered, (int) $r->delivered + (int) $r->failed),
                'avg_hours' => $r->hours !== null ? round((float) $r->hours, 1) : null,
                'avg_cost' => $r->avg_cost !== null ? (int) round((float) $r->avg_cost) : null,
                'collected' => (int) $r->collected,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function byPromotion(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('discount_usages')->join('orders', 'orders.id', '=', 'discount_usages.order_id')->join('discounts', 'discounts.id', '=', 'discount_usages.discount_id')
            ->where('orders.store_id', $store->id)->whereBetween('orders.created_at', [$from, $to])->where('orders.status', '!=', 'cancelled')
            ->groupBy('discounts.id', 'discounts.code', 'discounts.title')
            ->selectRaw("discounts.id, discounts.code, discounts.title, COUNT(*) AS orders, SUM(discount_usages.amount) AS discounted,
                SUM(CASE WHEN orders.status = 'completed' THEN orders.total ELSE 0 END) AS revenue")
            ->orderByDesc('orders')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->code ?? $r->title, 'kind' => $r->code ? 'code' : 'flash', 'orders' => (int) $r->orders, 'discounted' => (int) $r->discounted, 'revenue' => (int) $r->revenue])
            ->all();
    }

    /**
     * Bénéfice réel de la période : marge nette des commandes livrées, moins ce qui ne figure pas sur une commande
     * livrée : frais des paiements en ligne, livraisons des commandes retournées, primes et ajustements des livreurs,
     * dépenses de la boutique. Les achats de marchandises ne sont pas retirés une seconde fois : le prix d'achat des
     * produits vendus est déjà dans la marge.
     *
     * @return array<string, mixed>
     */
    public function profit(Store $store, CarbonImmutable $from, CarbonImmutable $to, int $netMargin): array
    {
        $orders = fn (string $status) => fn (Builder $q) => $q->select('id')->from('orders')->where('store_id', $store->id)
            ->whereBetween('created_at', [$from, $to])->where('status', $status);

        $paymentFees = (int) DB::table('payments')->whereIn('order_id', $orders('completed'))->where('kind', 'online')
            ->where('status', 'succeeded')->sum('fee');

        $returned = fn (Builder $q) => $q->select('id')->from('shipments')->whereIn('order_id', $orders('returned'));
        $returnCosts = (int) DB::table('shipments')->whereIn('order_id', $orders('returned'))->whereNotNull('carrier_connection_id')
            ->where(fn ($q) => $q->whereNull('fee_payer')->orWhere('fee_payer', 'store'))->sum('carrier_fee')
            + (int) DB::table('courier_earnings')->whereIn('shipment_id', $returned)->whereIn('type', ['delivery', 'failed_attempt'])->sum('amount');

        // Primes et ajustements (les avances ne sont pas une charge : elles seront retenues sur la paie)
        $courierExtras = (int) DB::table('courier_earnings')->where('store_id', $store->id)->whereIn('type', ['bonus', 'adjustment'])
            ->whereBetween('created_at', [$from, $to])->sum('amount');

        $expenses = DB::table('cash_movements')->where('store_id', $store->id)->where('type', 'expense')->whereBetween('occurred_at', [$from, $to])
            ->groupBy('category')->selectRaw('category, SUM(amount) AS total')->pluck('total', 'category')->map(fn ($v) => (int) $v);
        $stockPurchases = (int) ($expenses['stock'] ?? 0);
        $byCategory = $expenses->except('stock')->sortDesc()
            ->map(fn (int $total, ?string $category) => ['category' => $category, 'label' => CashMovement::CATEGORIES[$category] ?? 'Autre', 'total' => $total])
            ->values();
        $expensesTotal = (int) $byCategory->sum('total');

        return [
            'net_margin' => $netMargin,
            'payment_fees' => $paymentFees,
            'return_costs' => $returnCosts,
            'courier_extras' => $courierExtras,
            'expenses' => $expensesTotal,
            'expenses_by_category' => $byCategory->all(),
            'stock_purchases' => $stockPurchases,
            'profit' => $netMargin - $paymentFees - $returnCosts - $courierExtras - $expensesTotal,
        ];
    }

    /**
     * Coût des livraisons des commandes livrées : frais des transporteurs et paie des livreurs de la boutique.
     */
    private function shippingCosts(Store $store, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $orders = fn (Builder $q) => $q->select('id')->from('orders')->where('store_id', $store->id)->whereBetween('created_at', [$from, $to])->where('status', 'completed');

        $carriers = (int) DB::table('shipments')->whereIn('order_id', $orders)->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('fee_payer')->orWhere('fee_payer', 'store'))->whereNotNull('carrier_connection_id')->sum('carrier_fee');
        $couriers = (int) DB::table('courier_earnings')->whereIn('shipment_id', fn (Builder $q) => $q->select('id')->from('shipments')->whereIn('order_id', $orders))
            ->whereIn('type', ['delivery', 'failed_attempt'])->sum('amount');

        return $carriers + $couriers;
    }

    private function orders(Store $store, CarbonImmutable $from, CarbonImmutable $to)
    {
        return Order::forStore($store->id)->toBase()->whereBetween('orders.created_at', [$from, $to]);
    }

    private function rate(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }
}
