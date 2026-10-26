<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VerificationPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Models\SupportSession;
use App\Models\User;
use App\Services\Accounts\AccountDeletion;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Accounts\StoreRoles;
use App\Services\Ai\Assistant;
use App\Services\Console\SupportSessions;
use App\Services\Couriers\CourierRoster;
use App\Services\Materiel\MaterielShop;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;
use Spatie\Permission\PermissionRegistrar;

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

        // Boutique choisie, sinon la première boutique de l'adresse dont la personne fait partie
        if ($user->activeMembershipIn($this->tenancy->store()) === null) {
            $other = $this->addressStores($this->tenancy->store())->first(fn (Store $s) => $user->activeMembershipIn($s) !== null);
            if ($other === null) {
                throw ValidationException::withMessages(['phone' => 'Ce compte ne fait pas partie de l\'équipe de '.$this->tenancy->store()->name.'.']);
            }
            $this->tenancy->setStore($other);
            app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => $this->profile($user)]);
    }

    /**
     * « Me connecter par WhatsApp » : sans mot de passe, la personne prouve son numéro (message WhatsApp, ou code SMS
     * en dernier recours) et la page ouvre sa session (VerificationController). Mêmes règles que la connexion.
     */
    public function whatsapp(Request $request, PhoneVerifier $verifier): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30']], [], ['phone' => 'numéro de téléphone']);
        $phone = PhoneNumber::normalize($request->string('phone'));
        $user = $phone === null ? null : User::where('phone', $phone)->first();
        if ($user === null) {
            throw ValidationException::withMessages(['phone' => 'Aucun compte avec ce numéro. Vérifiez-le, ou demandez une invitation.']);
        }
        if (! $user->isActive()) {
            return response()->json(['message' => 'Compte suspendu.'], 403);
        }
        $store = $this->tenancy->store();
        if ($this->addressStores($store)->first(fn (Store $s) => $user->activeMembershipIn($s) !== null) === null) {
            throw ValidationException::withMessages(['phone' => 'Ce compte ne fait pas partie de l\'équipe de '.$store->name.'.']);
        }

        [$verification, $token, $demoCode] = $verifier->start(VerificationPurpose::Login, $phone, $store, $user, ip: $request->ip());

        return response()->json(VerificationController::pending($verification, $token, $demoCode, $verifier), 202);
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
     * Mon compte : nom et adresse email (rapport du jour par email, reçus).
     */
    public function updateAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->merge(['email' => $request->filled('email') ? Str::lower(trim((string) $request->input('email'))) : null]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], ['email.unique' => 'Cette adresse email est déjà utilisée par un autre compte.'], ['name' => 'votre nom', 'email' => 'adresse email']);

        $user->update($data);

        return response()->json(['data' => $this->profile($user)]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'confirmed'],
        ], [], ['current_password' => 'mot de passe actuel', 'password' => 'nouveau mot de passe']);

        $user = $request->user();
        if (! Hash::check($request->string('current_password'), (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe actuel incorrect.']);
        }
        $user->update(['password' => Hash::make($request->string('password'))]);

        return response()->json(['message' => 'Mot de passe modifié.']);
    }

    /**
     * Supprimer mon compte (mot de passe demandé) ; la session est fermée.
     */
    public function destroyAccount(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:100']], [], ['password' => 'mot de passe']);
        $deletion->delete($request->user(), (string) $request->string('password'));

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Votre compte a été supprimé.']);
    }

    /**
     * Passer à une autre de ses boutiques (lien de connexion à usage unique vers son adresse).
     */
    public function switch(Request $request, int $store, LoginHandoff $handoff): JsonResponse
    {
        $target = Store::findOrFail($store);
        $user = $request->user();

        abort_unless($target->isOpen() && $user->activeMembershipIn($target) !== null, 403, 'Vous n\'avez pas accès à cet espace marchand.');

        return response()->json(['redirect' => $handoff->url($user, $target)]);
    }

    /**
     * @return Collection<int, Store>
     */
    private function addressStores(Store $store): Collection
    {
        return $store->addressStores();
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
            // Matériel de la plateforme proposé (page « Matériel » et suggestions)
            'materiel' => app(MaterielShop::class)->open(),
            // Fiche livreur de la personne dans cette boutique : accès à l'application /livreur
            'courier' => ($courier = CourierRoster::of($user, $store)) ? ['id' => $courier->id, 'name' => $courier->name] : null,
            // Session de support : la personne connectée est le support (bandeau), ou un accès du support est ouvert
            'support' => ($own = app(SupportSessions::class)->ongoingFor($user, $store))
                ? ['self' => true, 'id' => $own->id, 'expires_at' => $own->expires_at->toIso8601String(), 'reason' => $own->reason]
                : (($open = SupportSession::forStore($store->id)->ongoing()->with('admin:id,name')->latest('id')->first())
                    ? ['self' => false, 'id' => $open->id, 'expires_at' => $open->expires_at->toIso8601String(), 'reason' => $open->reason, 'admin' => $open->admin?->name]
                    : null),
            // Boutiques de cette adresse (boutique hôte et boutiques hébergées) : choix sans changer d'adresse
            'stores' => $address = $this->addressStores($store)->filter(fn (Store $s) => $s->isOpen() && $user->activeMembershipIn($s) !== null)
                ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'is_host' => $s->host_store_id === null, 'url' => $s->url()])->values(),
            // Ses boutiques à d'autres adresses, pour passer de l'une à l'autre
            // Boutiques d'autres marchands pour lesquels la personne travaille aussi (« Autres comptes ») ; ses propres
            // boutiques à d'autres adresses se réunissent depuis la vue d'ensemble
            'other_stores' => $user->stores()->wherePivot('status', 'active')->where('stores.id', '!=', $store->id)
                ->whereNotIn('stores.id', $address->pluck('id'))->where('stores.owner_id', '!=', $user->id)
                ->orderBy('name')->get(['stores.id', 'stores.name', 'stores.slug', 'stores.status', 'stores.host_store_id'])
                ->filter(fn (Store $s) => $s->isOpen())
                ->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug])
                ->values(),
            // Propriétaire de plusieurs boutiques (à cette adresse ou ailleurs) : accès à la vue d'ensemble
            'owns_several' => Store::where('owner_id', $user->id)->count() > 1,
            // Assistant IA : activé sur la plateforme, actions utilisées et quota de l'offre ce mois-ci
            'ai' => app(Assistant::class)->status($store),
        ];
    }
}
