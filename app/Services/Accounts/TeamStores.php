<?php

namespace App\Services\Accounts;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use App\Services\Billing\PlanLimits;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Équipe sur plusieurs boutiques de la même adresse : une personne peut travailler pour plusieurs d'entre elles,
 * avec un rôle dans chacune. On n'agit que sur les boutiques où l'on gère soi-même l'équipe.
 */
class TeamStores
{
    public function __construct(
        private readonly StoreRoles $roles,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * Boutiques de l'adresse dont la personne gère l'équipe (propriétaire, ou droit « équipe » actif).
     *
     * @return Collection<int, Store>
     */
    public function manageable(User $actor, Store $current): Collection
    {
        return $current->addressStores()->filter(fn (Store $s) => $this->roles->can($actor, $s, Permission::TeamManage))->values();
    }

    /**
     * Boutiques supplémentaires d'une invitation, validées : boutiques gérées de l'adresse, rôle invitable.
     *
     * @param  array<int|string, string>  $stores  [store_id => rôle]
     * @return list<array{store_id: int, role: string}>
     */
    public function extras(User $actor, Store $current, array $stores): array
    {
        $manageable = $this->manageable($actor, $current)->keyBy('id');
        $extras = [];
        foreach ($stores as $id => $role) {
            $id = (int) $id;
            if ($id === $current->id) {
                continue;
            }
            $store = $manageable->get($id) ?? throw new BusinessRuleException('Vous ne gérez pas l\'équipe de cette boutique.', 'stores');
            if (! in_array($role, Role::invitableValues(), true) && $role !== Role::Courier->value) {
                throw new BusinessRuleException('Ce rôle ne peut pas être donné par invitation.', 'stores');
            }
            if ($role !== Role::Courier->value) {
                $this->limits->ensureCanAdd($store, 'members');
            }
            $extras[] = ['store_id' => $id, 'role' => $role];
        }

        return $extras;
    }

    /**
     * Boutiques de l'adresse où la personne travaille, avec son rôle et son statut.
     *
     * @return list<array<string, mixed>>
     */
    public function of(User $member, Store $current): array
    {
        $memberships = StoreUser::where('user_id', $member->id)->whereIn('store_id', $current->addressStores()->pluck('id'))->get()->keyBy('store_id');

        return $current->addressStores()->filter(fn (Store $s) => $memberships->has($s->id))
            ->map(function (Store $s) use ($member, $memberships) {
                $role = $this->roles->roleOf($member, $s);

                return [
                    'store_id' => $s->id,
                    'name' => $s->name,
                    'role' => $role?->value,
                    'role_label' => $role?->label(),
                    'status' => $memberships[$s->id]->status->value,
                    'is_owner' => $s->owner_id === $member->id,
                ];
            })->values()->all();
    }

    /**
     * Ajoute la personne à une boutique (son compte existe déjà), change son rôle, ou la retire (rôle null).
     */
    public function set(User $actor, User $member, Store $store, ?string $role): void
    {
        if ($store->owner_id === $member->id) {
            throw new BusinessRuleException("Le propriétaire de {$store->name} ne peut pas être modifié ni retiré.");
        }
        if ($member->id === $actor->id) {
            throw new BusinessRuleException('Vous ne pouvez pas modifier votre propre accès.');
        }

        $membership = $store->members()->where('user_id', $member->id)->first();
        if ($role === null) {
            if ($membership !== null) {
                DB::transaction(function () use ($member, $store, $membership) {
                    $this->roles->revoke($member, $store);
                    $membership->delete();
                });
            }

            return;
        }

        if (! in_array($role, Role::invitableValues(), true)) {
            throw new BusinessRuleException('Ce rôle ne peut pas être donné ici.', 'role');
        }
        if ($membership === null) {
            $this->limits->ensureCanAdd($store, 'members');
        }

        DB::transaction(function () use ($actor, $member, $store, $role, $membership) {
            if ($membership === null) {
                $store->members()->create(['user_id' => $member->id, 'status' => UserStatus::Active, 'invited_by' => $actor->id, 'joined_at' => now()]);
            }
            $this->roles->assign($member, $store, Role::from($role));
        });
    }
}
