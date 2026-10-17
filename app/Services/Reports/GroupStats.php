<?php

namespace App\Services\Reports;

use App\Models\Store;
use App\Services\Finance\CashOverview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Money;

/**
 * Chiffres de plusieurs boutiques d'un même propriétaire (abonnement commun) : statistiques de la période,
 * caisse et rapport du jour, pour l'ensemble et boutique par boutique.
 */
class GroupStats
{
    /**
     * Montants et nombres qui s'additionnent d'une boutique à l'autre.
     */
    private const SUMMED = ['orders', 'cancelled', 'decided', 'confirmed', 'delivered', 'returned', 'revenue', 'in_progress', 'discounts',
        'product_margin', 'shipping_income', 'shipping_costs', 'net_margin', 'without_cost'];

    public function __construct(
        private readonly StoreStats $stats,
        private readonly CashOverview $cash,
        private readonly DailyReport $daily,
    ) {}

    /**
     * @param  Collection<int, Store>  $stores
     * @return array<string, mixed>
     */
    public function report(Collection $stores, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $rows = $stores->map(function (Store $store) use ($from, $to) {
            $overview = $this->stats->overview($store, $from, $to);

            return ['id' => $store->id, 'name' => $store->name, 'overview' => $overview, 'profit' => $this->stats->profit($store, $from, $to, $overview['net_margin'])['profit']];
        });

        $total = collect(self::SUMMED)->mapWithKeys(fn ($key) => [$key => $rows->sum(fn ($r) => $r['overview'][$key])])->all();
        $active = $total['orders'] - $total['cancelled'];
        $basket = $rows->sum(fn ($r) => ($r['overview']['avg_basket'] ?? 0) * ($r['overview']['orders'] - $r['overview']['cancelled']));
        $total += [
            'avg_basket' => $active > 0 ? (int) round($basket / $active) : null,
            'confirmation_rate' => $total['decided'] > 0 ? round($total['confirmed'] / $total['decided'] * 100, 1) : null,
            'delivery_rate' => $total['delivered'] + $total['returned'] > 0 ? round($total['delivered'] / ($total['delivered'] + $total['returned']) * 100, 1) : null,
            'profit' => $rows->sum('profit'),
        ];

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total' => $total,
            'stores' => $rows->values()->all(),
            'daily' => $this->daily($stores, $from, $to, $timezone),
            'top_products' => $this->topProducts($stores, $from, $to),
        ];
    }

    /**
     * Commandes reçues et ventes livrées jour par jour, par boutique.
     *
     * @param  Collection<int, Store>  $stores
     * @return list<array{date: string, orders: int, revenue: int, by_store: array<int, int>}>
     */
    private function daily(Collection $stores, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $day = "DATE((orders.created_at AT TIME ZONE 'UTC') AT TIME ZONE ?)";
        $rows = DB::table('orders')->whereIn('store_id', $stores->pluck('id'))->whereBetween('created_at', [$from, $to])
            // Regroupement par position : PostgreSQL ne reconnaît pas deux fois la même expression paramétrée
            ->groupByRaw('1, 2')
            ->selectRaw("{$day} AS day, store_id, COUNT(*) AS orders, SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) AS revenue", [$timezone])
            ->get()->groupBy(fn ($r) => (string) $r->day);

        $days = [];
        for ($d = $from->setTimezone($timezone)->startOfDay(); $d->lte($to->setTimezone($timezone)); $d = $d->addDay()) {
            $lines = $rows->get($d->toDateString(), collect());
            $days[] = [
                'date' => $d->toDateString(),
                'orders' => (int) $lines->sum('orders'),
                'revenue' => (int) $lines->sum('revenue'),
                'by_store' => $lines->mapWithKeys(fn ($r) => [(int) $r->store_id => (int) $r->revenue])->all(),
            ];
        }

        return $days;
    }

    /**
     * Produits les plus vendus (commandes livrées), toutes boutiques confondues : un même nom réunit les boutiques.
     *
     * @param  Collection<int, Store>  $stores
     * @return list<array<string, mixed>>
     */
    private function topProducts(Collection $stores, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $names = $stores->pluck('name', 'id');

        return DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.store_id', $stores->pluck('id'))->whereBetween('orders.created_at', [$from, $to])->where('orders.status', 'completed')
            ->groupBy('order_items.title')
            ->selectRaw('order_items.title, SUM(order_items.quantity) AS quantity, SUM(order_items.total - order_items.discount_amount) AS revenue,
                STRING_AGG(DISTINCT orders.store_id::text, \',\') AS store_ids')
            ->orderByDesc('revenue')->limit(15)->get()
            ->map(fn ($r) => [
                'title' => $r->title,
                'quantity' => (int) $r->quantity,
                'revenue' => (int) $r->revenue,
                'stores' => collect(explode(',', (string) $r->store_ids))->map(fn ($id) => $names[(int) $id] ?? null)->filter()->values()->all(),
            ])->all();
    }

    /**
     * Où est l'argent de chaque boutique en ce moment, et le total.
     *
     * @param  Collection<int, Store>  $stores
     * @return array<string, mixed>
     */
    public function cash(Collection $stores, string $period): array
    {
        $keys = ['couriers_cash', 'carriers_due', 'carriers_due_overdue', 'customers_due', 'couriers_to_pay'];
        $rows = $stores->map(function (Store $store) use ($period, $keys) {
            $range = $this->cash->range($store, $period);
            $flows = $this->cash->flows($store, $range['from'], $range['to']);

            return ['id' => $store->id, 'name' => $store->name, 'url' => $store->url('/gestion/caisse')]
                + array_intersect_key($this->cash->position($store), array_flip($keys))
                + ['flows_total' => $flows['total'], 'flows_cash' => $flows['cash']];
        });

        return [
            'stores' => $rows->values()->all(),
            'total' => collect([...$keys, 'flows_total', 'flows_cash'])->mapWithKeys(fn ($k) => [$k => (int) $rows->sum($k)])->all(),
        ];
    }

    /**
     * Rapport du jour de plusieurs boutiques : une ligne par boutique, puis le total.
     *
     * @param  Collection<int, Store>  $stores
     * @return array{figures: array<string, int|string>, text: string, title: string}
     */
    public function dailyReport(Collection $stores, CarbonImmutable $day): array
    {
        $each = $stores->map(fn (Store $s) => ['store' => $s, 'figures' => $this->daily->figures($s, $day)]);
        $sum = fn (string $key) => (int) $each->sum(fn ($e) => $e['figures'][$key]);
        $figures = ['date' => $each->first()['figures']['date']] + collect(['orders', 'orders_amount', 'delivered', 'delivered_amount', 'cancelled', 'to_confirm', 'to_ship', 'in_delivery', 'courier_cash', 'carrier_due', 'carrier_due_overdue'])
            ->mapWithKeys(fn ($k) => [$k => $sum($k)])->all();

        $lines = $each->map(fn ($e) => "• {$e['store']->name} : {$e['figures']['orders']} commande(s), ".Money::format($e['figures']['delivered_amount']).' livrés, '
            ."{$e['figures']['to_confirm']} à confirmer");

        $title = $stores->count().' boutiques';
        $text = $this->daily->text((new Store)->forceFill(['name' => $title]), $figures)."\n\nPar boutique :\n".$lines->implode("\n");

        return ['figures' => $figures, 'text' => $text, 'title' => $title];
    }
}
