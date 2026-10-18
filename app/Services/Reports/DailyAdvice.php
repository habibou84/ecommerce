<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Order;
use App\Models\Store;
use App\Services\Shipping\CarrierBalances;
use App\Services\Stock\StockLevels;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\Money;

/**
 * Trois conseils du soir tirés des vrais chiffres de la boutique, par des règles simples et vérifiables (pas d'IA :
 * gratuit, chaque jour, sans rien inventer). Chaque règle propose au plus un conseil avec une importance ; les trois
 * plus importants sont retenus.
 */
class DailyAdvice
{
    public const MAX = 3;

    public function __construct(
        private readonly StoreStats $stats,
        private readonly StockLevels $levels,
    ) {}

    /**
     * @return list<string>
     */
    public function tips(Store $store, ?CarbonImmutable $day = null): array
    {
        $now = ($day ?? CarbonImmutable::now())->setTimezone($store->timezone);
        $month = $this->stats->report($store, $now->subDays(30)->startOfDay()->utc(), $now->endOfDay()->utc());
        $candidates = array_filter([
            $this->runningOut($store, $now),
            $this->waitingConfirmation($store, $now),
            ...$this->failingDeliveries($month),
            $this->costlyAds($month),
            $this->sleepingStock($store, $now),
            $this->trend($store, $now),
            $this->moneyToCollect($store),
        ]);
        usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);

        return array_slice(array_column($candidates, 1), 0, self::MAX);
    }

    /**
     * Article qui part vite : ventes des 7 derniers jours rapportées au stock disponible.
     *
     * @return array{0: int, 1: string}|null
     */
    private function runningOut(Store $store, CarbonImmutable $now): ?array
    {
        $sold = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->whereNotIn('orders.status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value])
            ->where('orders.created_at', '>=', $now->subDays(7)->utc())->whereNotNull('order_items.variant_id')
            ->groupBy('order_items.variant_id')->selectRaw('order_items.variant_id, SUM(order_items.quantity) AS sold');

        $row = DB::query()->fromSub($this->levels->variantAvailability($store), 'a')
            ->joinSub($sold, 's', 's.variant_id', '=', 'a.variant_id')
            ->join('product_variants as v', 'v.id', '=', 'a.variant_id')->join('products as p', 'p.id', '=', 'a.product_id')
            ->where('s.sold', '>=', 2)
            ->selectRaw('p.title, v.title AS variant, v.is_default, a.available, s.sold, a.available * 7.0 / s.sold AS days')
            ->orderBy('days')->first();
        if ($row === null || $row->days >= 7) {
            return null;
        }

        $name = $row->is_default ? $row->title : "{$row->title} ({$row->variant})";
        if ((int) $row->available <= 0) {
            return [95, "Réapprovisionnez « {$name} » : il est épuisé alors que {$row->sold} se sont vendus cette semaine."];
        }
        $days = max(1, (int) floor((float) $row->days));

        return [85, "Réapprovisionnez « {$name} » : {$row->sold} vendus cette semaine, plus que {$row->available} en stock (environ {$days} jour".($days > 1 ? 's' : '').')'];
    }

    /**
     * Commandes qui attendent une confirmation depuis plus d'un jour.
     *
     * @return array{0: int, 1: string}|null
     */
    private function waitingConfirmation(Store $store, CarbonImmutable $now): ?array
    {
        $count = Order::forStore($store->id)->where('status', OrderStatus::PendingConfirmation->value)
            ->where('created_at', '<', $now->subDay()->utc())->count();

        return $count > 0
            ? [80 + min($count, 15), "{$count} commande".($count > 1 ? 's attendent' : ' attend').' une confirmation depuis plus d\'un jour : appelez ces clients demain matin, le risque d\'annulation augmente.']
            : null;
    }

    /**
     * Zone ou livreur où beaucoup de colis reviennent (30 derniers jours, au moins 5 livraisons terminées).
     *
     * @param  array<string, mixed>  $report  statistiques des 30 derniers jours
     * @return list<array{0: int, 1: string}>
     */
    private function failingDeliveries(array $report): array
    {
        $tips = [];

        $zone = collect($report['by_zone'])->filter(fn ($z) => $z['delivered'] + $z['returned'] >= 5 && $z['delivery_rate'] !== null && $z['delivery_rate'] <= 70)
            ->sortBy('delivery_rate')->first();
        if ($zone) {
            $back = (int) round(100 - $zone['delivery_rate']);
            $tips[] = [70 + $back / 5, "À {$zone['name']}, {$back} % des colis sont revenus ce mois-ci : confirmez par appel avant d'expédier, ou demandez un acompte."];
        }

        $courier = collect($report['by_courier'])->filter(fn ($c) => $c['shipments'] >= 5 && $c['success_rate'] !== null && $c['success_rate'] <= 70)
            ->sortBy('success_rate')->first();
        if ($courier) {
            $back = (int) round(100 - $courier['success_rate']);
            $tips[] = [65 + $back / 5, "{$back} % des courses de {$courier['name']} ont échoué ce mois-ci : faites le point avec lui sur ces retours."];
        }

        return $tips;
    }

    /**
     * Publicité notée pour un canal (30 derniers jours, au moins 10 000 F) qui ne ramène aucune commande livrée,
     * ou qui coûte plus que la marge des commandes livrées de ce canal.
     *
     * @param  array<string, mixed>  $report  statistiques des 30 derniers jours
     * @return array{0: int, 1: string}|null
     */
    private function costlyAds(array $report): ?array
    {
        $channel = collect($report['by_source'])->filter(fn ($c) => ($c['ad_spend'] ?? 0) >= 10000 && $c['margin_after_ads'] < 0)
            ->sortBy('margin_after_ads')->first();
        if ($channel === null) {
            return null;
        }
        if ($channel['delivered'] === 0) {
            return [78, Money::format($channel['ad_spend'])." de publicité {$channel['label']} ce mois-ci sans aucune commande livrée venue de {$channel['label']} : vérifiez le canal noté sur vos commandes, ou revoyez cette publicité."];
        }

        return [74, "Votre publicité {$channel['label']} coûte ".Money::format($channel['ad_cost_per_delivered']).' par commande livrée, plus que ce que ces commandes vous laissent : ciblez mieux, mettez en avant des articles plus rentables ou ajustez vos prix.'];
    }

    /**
     * Article en stock qui ne s'est pas vendu depuis 30 jours (le plus gros stock en valeur).
     *
     * @return array{0: int, 1: string}|null
     */
    private function sleepingStock(Store $store, CarbonImmutable $now): ?array
    {
        $since = $now->subDays(30)->utc();
        $recent = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->where('orders.created_at', '>=', $since)->whereNotNull('order_items.product_id')
            ->select('order_items.product_id');

        $row = DB::query()->fromSub($this->levels->variantAvailability($store)->addSelect('v.price')->groupBy('v.price'), 'a')
            ->join('products as p', 'p.id', '=', 'a.product_id')
            ->where('p.status', ProductStatus::Active->value)->where('p.created_at', '<', $since)
            ->whereNotIn('a.product_id', $recent)->where('a.on_hand', '>', 0)
            ->groupBy('a.product_id', 'p.title')
            ->selectRaw('p.title, SUM(a.on_hand) AS units, SUM(a.on_hand * a.price) AS value')
            ->orderByDesc('value')->first();

        return $row && (int) $row->units >= 3
            ? [50, "« {$row->title} » ne s'est pas vendu depuis un mois ({$row->units} en stock) : mettez-le en avant ou faites une vente flash."]
            : null;
    }

    /**
     * Ventes livrées des 7 derniers jours comparées aux 7 précédents.
     *
     * @return array{0: int, 1: string}|null
     */
    private function trend(Store $store, CarbonImmutable $now): ?array
    {
        $delivered = fn (CarbonImmutable $from, CarbonImmutable $to) => (int) Order::forStore($store->id)->where('status', OrderStatus::Completed->value)
            ->whereBetween('completed_at', [$from->utc(), $to->utc()])->sum('total');
        $week = $delivered($now->subDays(6)->startOfDay(), $now->endOfDay());
        $before = $delivered($now->subDays(13)->startOfDay(), $now->subDays(7)->endOfDay());
        if ($before < 20000) {
            return null; // trop peu pour comparer
        }

        $change = (int) round(($week - $before) * 100 / $before);
        if ($change <= -30) {
            return [60, 'Vos ventes livrées ont baissé de '.abs($change).' % cette semaine ('.Money::format($week).') : relancez vos anciens clients ou lancez une promotion.'];
        }

        return $change >= 30
            ? [40, "Bonne semaine : vos ventes livrées ont augmenté de {$change} % (".Money::format($week).') : vérifiez que le stock des meilleurs articles suit.']
            : null;
    }

    /**
     * Argent des entreprises de livraison en retard de reversement.
     *
     * @return array{0: int, 1: string}|null
     */
    private function moneyToCollect(Store $store): ?array
    {
        $overdue = (int) app(CarrierBalances::class)->pending($store)->where('overdue', true)->sum('net');

        return $overdue > 0 ? [75, 'Réclamez '.Money::format($overdue).' que des entreprises de livraison auraient déjà dû vous reverser.'] : null;
    }
}
