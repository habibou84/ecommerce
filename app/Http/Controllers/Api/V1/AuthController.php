<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\StoreRoles;
use App\Services\Console\SupportSessions;
use App\Services\Couriers\CourierRoster;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;

/**
 * Connexion à la gestion d'une boutique ({slug}.jibiat-marchand.com/gestion), par session.
 */
class AuthController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy, private readonly StoreRoles $roles) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:100']]);

        $user = User::where('phone', PhoneNumber::normalize($request->string('phone')))->first();
        if ($user === null || ! Hash::check($request->string('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['phone' => 'Numéro ou mot de passe incorrect.']);
        }

        if (! $user->isActive()) {
            return response()->json(['message' => 'Compte suspendu.'], 403);
        }

        if ($user->activeMembershipIn($this->tenancy->store()) === null) {
            throw ValidationException::withMessages(['phone' => 'Ce compte ne fait pas partie de l\'équipe de '.$this->tenancy->store()->name.'.']);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => $this->profile($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /**
     * Passer à une autre de ses boutiques (lien de connexion à usage unique vers son adresse).
     */
    public function switch(Request $request, int $store, LoginHandoff $handoff): JsonResponse
    {
        $target = Store::findOrFail($store);
        $user = $request->user();

        abort_unless($target->isOpen() && $user->activeMembershipIn($target) !== null, 403, 'Vous n\'avez pas accès à cette boutique.');

        return response()->json(['redirect' => $handoff->url($user, $target)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        $store = $this->tenancy->store()->load('currentSubscription.plan');
        $role = $this->roles->roleOf($user, $store);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'phone_display' => PhoneNumber::display($user->phone),
                'email' => $user->email,
            ],
            'store' => StoreResource::make($store)->resolve(),
            'role' => $role ? ['value' => $role->value, 'label' => $role->label()] : null,
            'permissions' => $this->roles->permissionsOf($user, $store),
            'is_owner' => $store->owner_id === $user->id,
            // Fiche livreur de la personne dans cette boutique : accès à l'application /livreur
            'courier' => ($courier = CourierRoster::of($user, $store)) ? ['id' => $courier->id, 'name' => $courier->name] : null,
            // Session de support : la personne connectée est le support (bandeau), ou un accès du support est ouvert
            'support' => ($own = app(SupportSessions::class)->ongoingFor($user, $store))
                ? ['self' => true, 'id' => $own->id, 'expires_at' => $own->expires_at->toIso8601String(), 'reason' => $own->reason]
                : (($open = SupportSession::forStore($store->id)->ongoing()->with('admin:id,name')->latest('id')->first())
                    ? ['self' => false, 'id' => $open->id, 'expires_at' => $open->expires_at->toIso8601String(), 'reason' => $open->reason, 'admin' => $open->admin?->name]
                    : null),
            // Ses autres boutiques, pour passer de l'une à l'autre
            'other_stores' => $user->stores()->wherePivot('status', 'active')->where('stores.id', '!=', $store->id)
                ->orderBy('name')->get(['stores.id', 'stores.name', 'stores.slug', 'stores.status'])
                ->filter(fn (Store $s) => $s->isOpen())
                ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug])
                ->values(),
        ];
    }
}
