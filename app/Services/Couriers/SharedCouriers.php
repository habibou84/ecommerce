<?php

namespace App\Services\Couriers;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierPayout;
use App\Models\CourierPayRule;
use App\Models\CourierRemittance;
use App\Models\Scopes\StoreScope;
use App\Models\Shipment;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\StoreGroup;
use App\Services\Accounts\StoreRoles;
use App\Services\Billing\PlanLimits;
use App\Support\Tenancy;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Jibiat\Socle\PhoneNumber;

/**
 * Livreurs partagés entre les boutiques d'un même propriétaire (abonnement commun).
 *
 * Chaque boutique garde sa fiche du livreur (zones, règle de paie, plafond d'espèces, missions, encaissements,
 * gains) ; les fiches d'une même personne sont reliées par son compte. Son application réunit ses missions de
 * toutes ces boutiques, un versement unique est réparti entre elles et sa paie se règle en une fois.
 */
class SharedCouriers
{
    public const ACTIVE_MISSIONS = ['awaiting_pickup', 'in_transit', 'out_for_delivery', 'failed', 'rescheduled', 'returning'];

    public function __construct(
        private readonly StoreGroup $group,
        private readonly StoreRoles $roles,
        private readonly PlanLimits $limits,
        private readonly CashDesk $cash,
        private readonly CourierPayroll $payroll,
        private readonly CourierRoster $roster,
    ) {}

    /**
     * Exécute une opération dans le contexte d'une boutique (filtre et rattachement automatiques à cette boutique).
     */
    public static function within(Store $store, Closure $callback): mixed
    {
        $tenancy = app(Tenancy::class);
        $previous = $tenancy->store();
        $tenancy->setStore($store);

        try {
            return $callback();
        } finally {
            $tenancy->setStore($previous);
        }
    }

    /**
     * Fiches actives de la même personne dans les boutiques de l'abonnement commun (la fiche donnée comprise).
     *
     * @return Collection<int, Courier>
     */
    public function linked(Courier $courier): Collection
    {
        $courier->loadMissing('store');
        $stores = $courier->user_id !== null ? $this->group->stores($courier->store) : null;
        if ($stores === null) {
            return collect([$courier]);
        }

        // Seulement les boutiques où la personne est encore membre active (pas suspendue dans l'équipe)
        return Courier::withoutGlobalScope(StoreScope::class)->with('store')->whereIn('store_id', $stores->pluck('id'))
            ->where('user_id', $courier->user_id)->where('status', 'active')
            ->whereExists(fn ($q) => $q->from('store_users')->whereColumn('store_users.store_id', 'couriers.store_id')
                ->whereColumn('store_users.user_id', 'couriers.user_id')->where('store_users.status', UserStatus::Active->value))
            ->orderBy('store_id')->get();
    }

    /**
     * Livreurs des boutiques, regroupés par personne, avec leur situation dans chaque boutique.
     *
     * @param  Collection<int, Store>  $stores
     * @return list<array<string, mixed>>
     */
    public function people(Collection $stores): array
    {
        $names = $stores->pluck('name', 'id');

        return Courier::withoutGlobalScope(StoreScope::class)->whereIn('store_id', $stores->pluck('id'))->where('status', '!=', 'inactive')
            ->orderBy('name')->get()
            ->groupBy(fn (Courier $c) => $c->user_id !== null ? "user-{$c->user_id}" : "phone-{$c->phone}")
            ->map(function (Collection $rows) use ($stores, $names) {
                $lines = $rows->sortBy('store_id')->map(fn (Courier $c) => [
                    'courier_id' => $c->id,
                    'store_id' => $c->store_id,
                    'store_name' => $names[$c->store_id],
                    'status' => $c->status,
                    'cash_held' => $this->held($c),
                    'unpaid' => $this->payroll->unpaid($c),
                    'active_missions' => Shipment::forStore($c->store_id)->where('courier_id', $c->id)->whereIn('status', self::ACTIVE_MISSIONS)->count(),
                ])->values();
                $first = $rows->first();

                return [
                    'key' => $first->user_id ?? $first->phone,
                    'courier_id' => $first->id,
                    'name' => $first->name,
                    'phone' => $first->phone,
                    'phone_display' => PhoneNumber::display($first->phone),
                    'has_account' => $first->user_id !== null,
                    'stores' => $lines->all(),
                    'other_stores' => $stores->whereNotIn('id', $rows->pluck('store_id'))->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name])->values()->all(),
                    'cash_held' => (int) $lines->sum('cash_held'),
                    'unpaid' => (int) $lines->sum('unpaid'),
                    'active_missions' => (int) $lines->sum('active_missions'),
                ];
            })->values()->all();
    }

    /**
     * Le livreur travaille aussi pour une autre boutique : fiche créée (ou réactivée) avec son compte, sa règle de
     * paie recopiée (sans les montants par zone, propres à chaque boutique) et l'accès à l'application.
     */
    public function share(Courier $source, Store $target): Courier
    {
        if ($source->user_id === null) {
            throw new BusinessRuleException("{$source->name} doit d'abord activer son compte (lien d'invitation) pour travailler pour plusieurs boutiques.");
        }

        return self::within($target, function () use ($source, $target) {
            $existing = Courier::forStore($target->id)->where(fn ($q) => $q->where('user_id', $source->user_id)->orWhere('phone', $source->phone))->first();
            if ($existing?->status === 'active') {
                return $existing;
            }
            $this->limits->ensureCanAdd($target, 'couriers', Courier::forStore($target->id)->where('status', '!=', 'inactive')->count());

            return DB::transaction(function () use ($source, $target, $existing) {
                $courier = $existing ?? new Courier(['store_id' => $target->id]);
                $courier->fill([
                    'name' => $source->name, 'phone' => $source->phone, 'vehicle_type' => $source->vehicle_type, 'cash_limit' => $source->cash_limit,
                    'pay_rule_id' => $courier->pay_rule_id ?? $this->copyRule($source, $target), 'is_available' => $source->is_available,
                ])->forceFill(['user_id' => $source->user_id, 'status' => 'active'])->save();

                // Accès à l'application dans cette boutique ; un membre qui y a déjà un autre rôle le garde
                $user = User::findOrFail($source->user_id);
                $membership = $target->members()->where('user_id', $user->id)->first();
                if ($membership === null || $this->roles->roleOf($user, $target) === null) {
                    $target->members()->updateOrCreate(['user_id' => $user->id], ['status' => UserStatus::Active, 'joined_at' => now()]);
                    $this->roles->assign($user, $target, Role::Courier);
                } elseif ($membership->status !== UserStatus::Active) {
                    // Une personne suspendue dans l'équipe le reste : le partage ne lui rend pas son accès (ni son rôle)
                    throw new BusinessRuleException("{$source->name} est suspendu dans l'équipe de {$target->name} : réactivez-le d'abord depuis l'équipe de cette boutique.");
                }

                return $courier;
            });
        });
    }

    /**
     * Le livreur ne travaille plus pour cette boutique (ses autres boutiques ne changent pas).
     */
    public function unshare(Courier $courier): void
    {
        self::within($courier->store, fn () => $this->roster->deactivate($courier));
    }

    /**
     * Un seul versement pour toutes ses boutiques : chaque boutique reçoit sa part, au prorata des espèces qu'il
     * détient pour elle ; l'excédent éventuel va à la dernière.
     *
     * @param  Collection<int, Courier>  $couriers
     * @return Collection<int, CourierRemittance>
     */
    public function remit(Collection $couriers, int $received, string $method, ?string $note, User $receiver): Collection
    {
        $due = $couriers->map(fn (Courier $c) => ['courier' => $c, 'held' => $this->held($c)])->filter(fn ($d) => $d['held'] > 0)->values();
        if ($due->isEmpty()) {
            throw new BusinessRuleException('Ce livreur n\'a aucun encaissement à verser.');
        }
        $total = (int) $due->sum('held');

        return DB::transaction(function () use ($due, $total, $received, $method, $note, $receiver) {
            $left = $received;

            return $due->map(function (array $d, int $i) use ($due, $total, $received, $method, $note, $receiver, &$left) {
                $share = $i === $due->count() - 1 ? $left : ($received >= $total ? $d['held'] : intdiv($received * $d['held'], $total));
                $left -= $share;
                $label = $due->count() > 1 ? 'Versement commun à '.$due->count().' boutiques' : null;

                return self::within($d['courier']->store, fn () => $this->cash->remit($d['courier'], $share, $method, trim(($label ?? '').($note ? " · {$note}" : ''), ' ·') ?: null, $receiver));
            });
        });
    }

    /**
     * Paie de toutes ses boutiques réglée en une fois : un relevé payé par boutique.
     *
     * @param  Collection<int, Courier>  $couriers
     * @return Collection<int, CourierPayout>
     */
    public function payAll(Collection $couriers, string $method, ?string $reference, User $actor): Collection
    {
        $owed = $couriers->filter(fn (Courier $c) => $this->payroll->unpaid($c) > 0)->values();
        if ($owed->isEmpty()) {
            throw new BusinessRuleException('Rien à payer à ce livreur.');
        }

        return DB::transaction(fn () => $owed->map(fn (Courier $c) => self::within($c->store, function () use ($c, $method, $reference, $actor) {
            return $this->payroll->markPaid($this->payroll->createPayout($c, now(), $actor), $method, $reference, $actor);
        })));
    }

    public function held(Courier $courier): int
    {
        return (int) CashCollection::forStore($courier->store_id)->where('courier_id', $courier->id)->whereNull('remittance_id')->where('method', 'cash')->sum('amount');
    }

    private function copyRule(Courier $source, Store $target): ?int
    {
        $rule = $source->pay_rule_id ? CourierPayRule::forStore($source->store_id)->find($source->pay_rule_id) : null;
        if ($rule === null) {
            return null;
        }

        return (CourierPayRule::forStore($target->id)->where('name', $rule->name)->first()
            ?? CourierPayRule::create(['store_id' => $target->id, 'name' => $rule->name, 'type' => $rule->type, 'amount' => $rule->amount,
                'failed_attempt_amount' => $rule->failed_attempt_amount]))->id;
    }
}
