<?php

namespace App\Services\Shipping;

use App\Enums\ShipmentStatus;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\Shipment;
use App\Models\Store;
use App\Services\Couriers\CashDesk;
use App\Services\Orders\OrderAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce qui est dehors : l'argent encaissé et les colis chez les livreurs de la boutique et chez les entreprises de
 * livraison, avec ce qui traîne selon les délais des alertes (argent gardé, reversement en retard, colis pas rapporté).
 * L'argent en retard passe au rouge à deux fois le délai.
 */
class OutsideStock
{
    /** Colis en route vers le client (ou à relivrer un autre jour) */
    public const ON_THE_WAY = [ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Rescheduled];

    /** Colis pas livré, à rapporter à la boutique */
    public const TO_BRING_BACK = [ShipmentStatus::Failed, ShipmentStatus::Returning];

    public function __construct(private readonly CashDesk $cash, private readonly CarrierBalances $balances, private readonly OrderAlerts $alerts) {}

    /**
     * Livreurs de la boutique (null s'il n'y en a aucun).
     *
     * @return array{cash: int, holders: int, on_the_way: int, to_bring_back: int, late_returns: int, over_limit: list<string>, late: list<array{courier_id: int, name: string, held: int, hours: int, since: string, level: int}>}|null
     */
    public function couriers(Store $store, ?CarbonImmutable $now = null): ?array
    {
        if (! Courier::forStore($store->id)->where('status', '!=', 'invited')->exists()) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $hours = $this->alerts->settings($store)['courier_cash']['hours'];
        $rows = $this->cash->summary($store)->where('held', '>', 0);

        return [
            'cash' => (int) $rows->sum('held'),
            'holders' => $rows->count(),
            'on_the_way' => $this->parcels($store, 'courier_id', self::ON_THE_WAY)->count(),
            'to_bring_back' => $this->parcels($store, 'courier_id', self::TO_BRING_BACK)->count(),
            'late_returns' => $this->lateReturns($store, 'courier_id', $now),
            'over_limit' => $rows->where('over_limit', true)->pluck('name')->values()->all(),
            // Argent gardé depuis le plus ancien encaissement pas versé
            'late' => $rows->map(function (array $r) use ($now, $hours) {
                $since = CarbonImmutable::parse($r['oldest_at']);
                $held = (int) $since->diffInHours($now);

                return ['courier_id' => $r['courier_id'], 'name' => $r['name'], 'held' => $r['held'], 'hours' => $held, 'since' => $since->toIso8601String(),
                    'level' => $held >= 2 * $hours ? 2 : ($held >= $hours ? 1 : 0)];
            })->filter(fn (array $r) => $r['level'] > 0)->sortByDesc('hours')->values()->all(),
        ];
    }

    /**
     * Entreprises de livraison (null s'il n'y en a aucune).
     *
     * @return array{amount: int, on_the_way: int, to_bring_back: int, late_returns: int, overdue: list<array{connection_id: int, carrier: string, amount: int, count: int, days: int, since: string, level: int}>}|null
     */
    public function carriers(Store $store, ?CarbonImmutable $now = null): ?array
    {
        if (! CarrierConnection::forStore($store->id)->exists()) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $days = $this->balances->overdueDays($store);
        $pending = $this->balances->pending($store);
        $names = CarrierConnection::forStore($store->id)->pluck('name', 'id');

        return [
            'amount' => (int) $pending->sum('net'),
            'on_the_way' => $this->parcels($store, 'carrier_connection_id', self::ON_THE_WAY)->count(),
            'to_bring_back' => $this->parcels($store, 'carrier_connection_id', self::TO_BRING_BACK)->count(),
            'late_returns' => $this->lateReturns($store, 'carrier_connection_id', $now),
            'overdue' => $pending->where('overdue', true)->groupBy('connection_id')->map(fn ($lines, int $id) => [
                'connection_id' => $id,
                'carrier' => (string) ($names[$id] ?? 'Entreprise'),
                'amount' => (int) $lines->sum('net'),
                'count' => $lines->count(),
                'days' => (int) $lines->max('days'),
                'since' => (string) $lines->min('delivered_at'),
                'level' => $lines->max('days') >= 2 * $days ? 2 : 1,
            ])->sortByDesc('days')->values()->all(),
        ];
    }

    /**
     * Colis pas livrés, à rapporter depuis plus du délai des alertes.
     *
     * @return Builder<Shipment>
     */
    public function lateReturnsQuery(Store $store, CarbonImmutable $now): Builder
    {
        $hours = $this->alerts->settings($store)['returns']['hours'];

        return Shipment::forStore($store->id)->whereIn('status', self::values(self::TO_BRING_BACK))
            ->where(fn (Builder $q) => $q->where('status_changed_at', '<=', $now->subHours($hours)->utc())
                ->orWhere(fn (Builder $q) => $q->whereNull('status_changed_at')->where('updated_at', '<=', $now->subHours($hours)->utc())));
    }

    private function lateReturns(Store $store, string $column, CarbonImmutable $now): int
    {
        return $this->lateReturnsQuery($store, $now)->whereNotNull($column)->count();
    }

    /**
     * @param  list<ShipmentStatus>  $statuses
     * @return Builder<Shipment>
     */
    private function parcels(Store $store, string $column, array $statuses): Builder
    {
        return Shipment::forStore($store->id)->whereNotNull($column)->whereIn('status', self::values($statuses));
    }

    /**
     * @param  list<ShipmentStatus>  $cases
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(fn (ShipmentStatus $c) => $c->value, $cases);
    }
}
