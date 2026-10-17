<?php

namespace App\Services\Accounts;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôle d'une personne dans une boutique. Spatie lit l'équipe courante (store_id) dans le registre :
 * on la fixe le temps de l'opération, puis on rétablit celle de la requête.
 */
class StoreRoles
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function assign(User $user, Store $store, Role $role): void
    {
        $this->within($store, $user, fn () => $user->syncRoles([$role->value]));
    }

    public function revoke(User $user, Store $store): void
    {
        $this->within($store, $user, fn () => $user->syncRoles([]));
    }

    public function roleOf(User $user, Store $store): ?Role
    {
        return $this->within($store, $user, fn () => Role::tryFrom((string) $user->roles()->value('name')));
    }

    /**
     * @return list<string>
     */
    public function permissionsOf(User $user, Store $store): array
    {
        return $this->within($store, $user, fn () => $user->getAllPermissions()->pluck('name')->sort()->values()->all());
    }

    /**
     * La personne a-t-elle ce droit dans la boutique ? (propriétaire, ou membre actif dont le rôle le donne)
     */
    public function can(User $user, Store $store, Permission $permission): bool
    {
        return $store->owner_id === $user->id
            || ($user->activeMembershipIn($store) !== null && in_array($permission->value, $this->permissionsOf($user, $store), true));
    }

    /**
     * Autres boutiques de la même adresse où la personne a ce droit.
     *
     * @return Collection<int, Store>
     */
    public function otherAddressStores(User $user, Store $current, Permission $permission): Collection
    {
        return $current->addressStores()->reject(fn (Store $s) => $s->id === $current->id)
            ->filter(fn (Store $s) => $this->can($user, $s, $permission))->values();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function within(Store $store, User $user, callable $callback): mixed
    {
        $previous = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($store->id);
        // Les rôles déjà chargés sur le modèle peuvent être ceux d'une autre boutique
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $callback();
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
