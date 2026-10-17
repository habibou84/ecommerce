<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreUser;
use App\Services\Accounts\StoreRoles;
use App\Services\Accounts\TeamStores;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;

/**
 * Équipe de la boutique : rôles, suspension, retrait.
 */
class MemberController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy, private readonly StoreRoles $roles, private readonly TeamStores $team) {}

    /**
     * Boutiques de l'adresse dont la personne connectée gère l'équipe (choix des boutiques d'un membre).
     */
    public function stores(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->team->manageable($request->user(), $this->tenancy->store())
            ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'is_current' => $s->id === $this->tenancy->store()->id])->values()]);
    }

    /**
     * Boutiques où travaille le membre : ajout, rôle, retrait (seulement dans les boutiques gérées).
     */
    public function updateStores(Request $request, int $member): JsonResponse
    {
        $membership = $this->find($member);
        $this->ensureEditable($request, $membership);
        $data = $request->validate(['stores' => ['required', 'array'], 'stores.*' => ['nullable', 'string']]);

        $manageable = $this->team->manageable($request->user(), $this->tenancy->store())->keyBy('id');
        foreach ($data['stores'] as $id => $role) {
            $store = $manageable->get((int) $id) ?? throw new BusinessRuleException('Vous ne gérez pas l\'équipe de cette boutique.', 'stores');
            $this->team->set($request->user(), $membership->user, $store, $role ?: null);
        }

        return response()->json(['data' => $this->present($membership->fresh('user'))]);
    }

    public function index(): JsonResponse
    {
        $store = $this->tenancy->store();

        $members = $store->members()->with('user')->orderBy('joined_at')->get()
            ->map(fn (StoreUser $m) => $this->present($m));

        return response()->json(['data' => $members]);
    }

    /**
     * Rôles disponibles et leurs permissions (écran « Équipe »).
     */
    public function roles(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (Role $r) => [
            'value' => $r->value,
            'label' => $r->label(),
            'description' => $r->description(),
            'invitable' => in_array($r, Role::invitable(), true),
            'permissions' => array_map(fn ($p) => $p->label(), $r->permissions()),
        ], array_filter(Role::cases(), fn (Role $r) => ! in_array($r, [Role::Courier, Role::Support], true)))]);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $membership = $this->find($member);
        $this->ensureEditable($request, $membership);

        $data = $request->validate([
            'role' => ['sometimes', Rule::in(Role::invitableValues())],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ]);

        if (isset($data['role'])) {
            $this->roles->assign($membership->user, $membership->store, Role::from($data['role']));
        }
        if (isset($data['status'])) {
            $membership->update(['status' => $data['status']]);
        }

        return response()->json(['data' => $this->present($membership->fresh('user'))]);
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        $membership = $this->find($member);
        $this->ensureEditable($request, $membership);

        $this->roles->revoke($membership->user, $membership->store);
        $membership->delete();

        return response()->json(['message' => 'Retiré de l\'équipe.']);
    }

    private function find(int $id): StoreUser
    {
        return $this->tenancy->store()->members()->with(['user', 'store'])->findOrFail($id);
    }

    private function ensureEditable(Request $request, StoreUser $membership): void
    {
        if ($membership->user_id === $membership->store->owner_id) {
            throw new BusinessRuleException('Le propriétaire de la boutique ne peut pas être modifié ni retiré.');
        }
        if ($membership->user_id === $request->user()->id) {
            throw new BusinessRuleException('Vous ne pouvez pas modifier votre propre accès.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StoreUser $membership): array
    {
        $role = $this->roles->roleOf($membership->user, $this->tenancy->store());

        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'name' => $membership->user->name,
            'phone' => PhoneNumber::display($membership->user->phone),
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'is_owner' => $membership->user_id === $this->tenancy->store()->owner_id,
            'status' => $membership->status->value,
            'joined_at' => $membership->joined_at?->toIso8601String(),
            // Boutiques de l'adresse où la personne travaille, avec son rôle dans chacune
            'stores' => $this->team->of($membership->user, $this->tenancy->store()),
            'last_seen_at' => $membership->last_seen_at?->toIso8601String(),
        ];
    }
}
