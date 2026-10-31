<?php

namespace App\Services\Shipping;

use App\Enums\OrderStatus;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\CourierAssignment;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingRule;
use App\Services\Orders\OrderDesk;
use Throwable;

/**
 * Comparateur des façons de livrer une commande (mes livreurs, transporteurs connectés) avec leur historique,
 * et règles d'affectation : la première règle qui correspond désigne qui livre ; en mode automatique,
 * l'expédition est créée dès la confirmation.
 */
class DeliveryAdvisor
{
    /** Historique pris en compte pour les taux de réussite et délais */
    public const HISTORY_DAYS = 90;

    /** En dessous, l'historique de la zone est jugé trop maigre : on prend celui de toutes les zones */
    private const MIN_ZONE_SAMPLE = 3;

    public function __construct(private readonly ShipmentDesk $shipments, private readonly OrderDesk $orders) {}

    /**
     * @return array{couriers: list<array<string, mixed>>, carriers: list<array<string, mixed>>}
     */
    public function options(Order $order): array
    {
        $order->loadMissing('items.product');
        $zoneId = $order->delivery_zone_id;

        // Livreurs qui ont déjà refusé cette commande : on ne la leur propose plus
        $declined = CourierAssignment::forStore($order->store_id)->where('status', 'declined')
            ->whereIn('shipment_id', Shipment::forStore($order->store_id)->where('order_id', $order->id)->select('id'))->pluck('courier_id')->all();

        $couriers = Courier::forStore($order->store_id)->whereIn('status', ['active', 'invited'])->with(['zones:id', 'payRule'])
            ->withCount(['shipments as active_missions' => fn ($q) => $q->whereIn('status', ['awaiting_pickup', 'in_transit', 'out_for_delivery'])])
            ->get()
            ->map(fn (Courier $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_available' => $c->is_available,
                // Créé mais pas encore connecté : proposé à la main, jamais choisi par les règles automatiques
                'connected' => $c->status === 'active',
                'active_missions' => $c->active_missions,
                'covers_zone' => $zoneId !== null && $c->zones->contains('id', $zoneId),
                'declined' => in_array($c->id, $declined, true),
                'cost' => $c->payRule?->amountFor($zoneId),
                'stats' => $this->stats($order->store_id, 'courier_id', $c->id, $zoneId),
            ])
            ->sortBy(fn ($c) => [$c['covers_zone'] ? 0 : 1, $c['is_available'] ? 0 : 1, $c['active_missions']])->values()->all();

        $connections = CarrierConnection::forStore($order->store_id)->where('status', 'active')->get()->keyBy('id');
        $carriers = array_map(fn (array $q) => $q + [
            'cost' => $q['fee'],
            'stats' => $this->stats($order->store_id, 'carrier_connection_id', $q['connection_id'], $zoneId),
            'is_api' => $connections->get($q['connection_id'])?->isApi() ?? false,
        ], $this->shipments->quotes($order));

        return ['couriers' => $couriers, 'carriers' => $carriers];
    }

    /**
     * Qui devrait livrer, d'après les règles de la boutique (ou, sans règle qui corresponde, la suggestion par défaut).
     *
     * @param  array{couriers: list<array<string, mixed>>, carriers: list<array<string, mixed>>}|null  $options
     * @return array{type: string, id: int, name: string, reason: string, rule_id: ?int}|null
     */
    public function suggest(Order $order, ?array $options = null): ?array
    {
        if ($order->delivery_method === 'pickup') {
            return null;
        }
        $options ??= $this->options($order);

        foreach (ShippingRule::forStore($order->store_id)->where('is_active', true)->orderBy('priority')->orderBy('id')->get() as $rule) {
            if (! $this->matches($rule, $order)) {
                continue;
            }
            if ($rule->action === 'manual') {
                return null;
            }
            $choice = $this->pick($rule->action, $options, $rule->carrier_connection_id);
            if ($choice !== null) {
                return $choice + ['reason' => 'Règle « '.$rule->name.' »', 'rule_id' => $rule->id];
            }
        }

        // Par défaut : un de mes livreurs du quartier, sinon le transporteur le moins cher
        $choice = $this->pick('own_courier', $options) ?? $this->pick('cheapest', $options);

        return $choice ? $choice + ['reason' => 'Suggestion par défaut', 'rule_id' => null] : null;
    }

    /**
     * Dès la confirmation : expédition confiée à qui a été choisi à la saisie de la commande, sinon (mode automatique)
     * à qui désignent les règles. Un échec est noté au journal de la commande.
     */
    public function autoAssign(Order $order): ?Shipment
    {
        $order = $order->fresh(['store', 'items.product', 'shipments', 'plannedCourier', 'plannedCarrier']);
        if ($order->status !== OrderStatus::Confirmed || $order->delivery_method === 'pickup'
            || $order->shipments->contains(fn (Shipment $s) => ! $s->status->isFinal())) {
            return null;
        }

        $suggestion = $this->planned($order);
        if ($suggestion === null && $order->store->assignment_mode === 'auto') {
            $suggestion = $this->suggest($order);
        }
        if ($suggestion === null) {
            return null;
        }

        try {
            $shipment = $suggestion['type'] === 'courier'
                ? $this->shipments->assignCourier($order, Courier::forStore($order->store_id)->findOrFail($suggestion['id']), ['note' => null], null)
                : $this->shipments->create($order, CarrierConnection::forStore($order->store_id)->findOrFail($suggestion['id']), [], null);
        } catch (Throwable $e) {
            $this->orders->log($order, 'auto_assignment_failed', ['to' => $suggestion['name'], 'error' => mb_substr($e->getMessage(), 0, 200)], null, 'system');

            return null;
        }
        $this->orders->log($order->fresh(), 'auto_assigned', ['to' => $suggestion['name'], 'reason' => $suggestion['reason']], null, 'system');

        return $shipment;
    }

    /**
     * Choix fait à la saisie de la commande (seulement à la première expédition : après un échec, on choisit à nouveau).
     *
     * @return array{type: string, id: int, name: string, reason: string, rule_id: null}|null
     */
    private function planned(Order $order): ?array
    {
        if ($order->shipments->isNotEmpty()) {
            return null;
        }
        if ($order->plannedCourier) {
            return ['type' => 'courier', 'id' => $order->plannedCourier->id, 'name' => $order->plannedCourier->name, 'reason' => 'Choisi à la saisie', 'rule_id' => null];
        }
        if ($order->plannedCarrier) {
            return ['type' => 'carrier', 'id' => $order->plannedCarrier->id, 'name' => $order->plannedCarrier->name, 'reason' => 'Choisi à la saisie', 'rule_id' => null];
        }

        return null;
    }

    /**
     * @param  array{couriers: list<array<string, mixed>>, carriers: list<array<string, mixed>>}  $options
     * @return array{type: string, id: int, name: string}|null
     */
    private function pick(string $action, array $options, ?int $connectionId = null): ?array
    {
        $couriers = array_values(array_filter($options['couriers'], fn ($c) => $c['covers_zone'] && $c['is_available'] && ($c['connected'] ?? true) && ! ($c['declined'] ?? false)));
        // Transporteur utilisable : devis obtenu (ou transporteur sans API, prévenu par WhatsApp)
        $carriers = array_values(array_filter($options['carriers'], fn ($c) => $c['error'] === null && ($c['fee'] !== null || ! $c['is_api'])));
        $courier = fn ($c) => ['type' => 'courier', 'id' => $c['id'], 'name' => $c['name'], 'cost' => $c['cost'], 'hours' => $c['stats']['avg_hours']];
        $carrier = fn ($c) => ['type' => 'carrier', 'id' => $c['connection_id'], 'name' => $c['name'], 'cost' => $c['fee'], 'hours' => $c['stats']['avg_hours']];
        $all = [...array_map($courier, $couriers), ...array_map($carrier, $carriers)];

        $choice = match ($action) {
            'own_courier' => isset($couriers[0]) ? $courier($couriers[0]) : null,
            'carrier' => ($c = collect($carriers)->firstWhere('connection_id', $connectionId)) ? $carrier($c) : null,
            'cheapest' => collect($all)->filter(fn ($o) => $o['cost'] !== null)->sortBy('cost')->first() ?? ($all[0] ?? null),
            'fastest' => collect($all)->filter(fn ($o) => $o['hours'] !== null)->sortBy('hours')->first()
                ?? collect($all)->filter(fn ($o) => $o['cost'] !== null)->sortBy('cost')->first(),
            default => null,
        };

        return $choice ? ['type' => $choice['type'], 'id' => $choice['id'], 'name' => $choice['name']] : null;
    }

    private function matches(ShippingRule $rule, Order $order): bool
    {
        $c = $rule->conditions ?? [];
        $fragile = $order->items->contains(fn ($i) => $i->product?->is_fragile);
        $weight = (int) $order->items->sum(fn ($i) => ($i->weight_g ?? 0) * $i->quantity);

        return (empty($c['delivery_zone_ids']) || in_array($order->delivery_zone_id, array_map('intval', $c['delivery_zone_ids']), true))
            && (! isset($c['is_fragile']) || $c['is_fragile'] === null || (bool) $c['is_fragile'] === $fragile)
            && (! isset($c['min_amount']) || $c['min_amount'] === null || $order->total >= $c['min_amount'])
            && (! isset($c['max_amount']) || $c['max_amount'] === null || $order->total <= $c['max_amount'])
            && (! isset($c['max_weight_g']) || $c['max_weight_g'] === null || $weight <= $c['max_weight_g']);
    }

    /**
     * Taux de réussite et délai moyen (demande → livraison) d'un livreur ou d'un transporteur, dans la zone si
     * l'historique y est suffisant.
     *
     * @return array{shipments: int, success_rate: ?float, avg_hours: ?float, scope: string}
     */
    public function stats(int $storeId, string $column, int $id, ?int $zoneId): array
    {
        $query = fn (?int $zone) => Shipment::forStore($storeId)->where("shipments.{$column}", $id)
            ->where('shipments.created_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->whereIn('shipments.status', ['delivered', 'returned', 'lost'])
            ->when($zone, fn ($q) => $q->join('orders', 'orders.id', '=', 'shipments.order_id')->where('orders.delivery_zone_id', $zone))
            ->selectRaw("COUNT(*) AS total, SUM(CASE WHEN shipments.status = 'delivered' THEN 1 ELSE 0 END) AS delivered")
            ->selectRaw("AVG(CASE WHEN shipments.status = 'delivered' THEN EXTRACT(EPOCH FROM (shipments.delivered_at - shipments.requested_at)) / 3600 END) AS hours")
            ->first();

        $scope = 'zone';
        $row = $zoneId ? $query($zoneId) : null;
        if ($row === null || (int) $row->total < self::MIN_ZONE_SAMPLE) {
            $row = $query(null);
            $scope = 'all';
        }
        $total = (int) $row->total;

        return [
            'shipments' => $total,
            'success_rate' => $total > 0 ? round((int) $row->delivered / $total * 100, 1) : null,
            'avg_hours' => $row->hours !== null ? round((float) $row->hours, 1) : null,
            'scope' => $scope,
        ];
    }
}
