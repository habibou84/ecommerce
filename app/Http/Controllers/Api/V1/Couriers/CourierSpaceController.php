<?php

namespace App\Http\Controllers\Api\V1\Couriers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\CourierProfile;
use App\Models\Place;
use App\Models\Scopes\StoreScope;
use App\Models\User;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Couriers\CourierNetwork;
use App\Services\Couriers\CourierSignup;
use App\Services\Couriers\SharedCouriers;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace du livreur, indépendant des boutiques : inscription et connexion au lien commun (jibiat-marchand.com/livreur),
 * son profil (véhicule, communes, photo, pièce d'identité, annuaire), ses boutiques et les demandes des boutiques.
 * Le même espace est dans « Mon compte » de l'application livreur de chaque boutique.
 */
class CourierSpaceController extends Controller
{
    public function __construct(private readonly CourierNetwork $network) {}

    /** Communes et quartiers proposés à l'inscription et dans le profil */
    public function places(): JsonResponse
    {
        return response()->json(['data' => Place::whereNull('store_id')->where('is_active', true)->whereIn('type', ['commune', 'neighborhood'])
            ->with('parent')->orderBy('name')->get()
            ->map(fn (Place $p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'parent' => $p->parent?->name])->values(),
            'vehicles' => Courier::VEHICLES]);
    }

    public function signup(Request $request, CourierSignup $signup, PhoneVerifier $verifier): JsonResponse
    {
        if (is_string($request->input('phone')) && PhoneNumber::isValid($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'phone' => ['required', new PhoneRule],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'vehicle_type' => ['required', Rule::in(array_keys(Courier::VEHICLES))],
            'place_ids' => ['sometimes', 'array', 'max:50'],
            'place_ids.*' => ['integer'],
            'listed' => ['sometimes', 'boolean'],
        ], [], ['name' => 'nom', 'phone' => 'numéro WhatsApp', 'password' => 'mot de passe', 'vehicle_type' => 'véhicule']);
        [$verification, $token, $demoCode] = $signup->start($data, $request->ip());

        return response()->json(VerificationController::pending($verification, $token, $demoCode, $verifier), 202);
    }

    /**
     * Connexion au lien commun. Livreur d'au moins une boutique : lien vers son application (boutique d'accueil) ;
     * sinon il reste ici, dans son espace (profil, demandes des boutiques).
     */
    public function login(Request $request, LoginHandoff $handoff): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:100']]);
        $user = User::where('phone', PhoneNumber::normalize($request->string('phone')))->first();
        if ($user === null || ! Hash::check($request->string('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['phone' => 'Numéro ou mot de passe incorrect.']);
        }
        if (! $user->isActive()) {
            return response()->json(['message' => 'Compte suspendu.'], 403);
        }
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return response()->json(['data' => ['redirect' => $this->appUrl($user, $handoff)]]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->network->profileFor($user)->load('places.parent');
        $fiches = Courier::withoutGlobalScope(StoreScope::class)->with('store', 'payRule')->where('user_id', $user->id)
            ->whereIn('status', ['active', 'requested'])->orderBy('id')->get();

        return response()->json(['data' => [
            'name' => $user->name,
            'phone' => $user->phone,
            'phone_display' => PhoneNumber::display($user->phone),
            // Code QR du numéro : la boutique le scanne pour l'ajouter
            'qr' => (new QRCode)->render((string) $user->phone),
            'profile' => [
                'vehicle_type' => $profile->vehicle_type,
                'listed' => $profile->listed,
                'places' => $profile->places->map(fn (Place $p) => ['id' => $p->id, 'name' => $p->name, 'parent' => $p->parent?->name])->values(),
                'photo_url' => $profile->photo_path ? '/api/v1/courier-space/photo?v='.$profile->updated_at?->timestamp : null,
                'has_id_document' => $profile->hasIdDocument(),
                'id_document_at' => $profile->id_document_at?->toIso8601String(),
            ],
            'vehicles' => Courier::VEHICLES,
            'stores' => $fiches->where('status', 'active')->map(fn (Courier $c) => [
                'courier_id' => $c->id, 'name' => $c->store->name, 'cash_held' => app(SharedCouriers::class)->held($c),
            ])->values(),
            'requests' => $fiches->where('status', 'requested')->map(fn (Courier $c) => [
                'courier_id' => $c->id,
                'store' => $c->store->name,
                'requested_at' => $c->requested_at?->toIso8601String(),
                'pay_rule' => $c->payRule?->name,
                'cash_limit' => $c->cash_limit,
                // Pièce d'identité exigée par cette boutique (sans elle, impossible d'accepter)
                'require_id' => (bool) $c->store->couriers_require_id,
            ])->values(),
        ]]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_type' => ['sometimes', Rule::in(array_keys(Courier::VEHICLES))],
            'listed' => ['sometimes', 'boolean'],
            'place_ids' => ['sometimes', 'array', 'max:50'],
            'place_ids.*' => ['integer'],
        ]);
        $this->network->updateProfile($this->network->profileFor($request->user()), $data);

        return $this->show($request);
    }

    public function savePhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'image', 'max:8192']], [], ['photo' => 'photo']);
        $this->network->savePhoto($this->network->profileFor($request->user()), $request->file('photo'));

        return $this->show($request);
    }

    public function photo(Request $request): StreamedResponse
    {
        $profile = CourierProfile::where('user_id', $request->user()->id)->whereNotNull('photo_path')->firstOrFail();

        return Storage::disk('local')->response($profile->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function saveIdDocument(Request $request): JsonResponse
    {
        $request->validate(['document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240']], [], ['document' => 'pièce d\'identité']);
        $this->network->saveIdDocument($this->network->profileFor($request->user()), $request->file('document'));

        return $this->show($request);
    }

    public function accept(Request $request, int $courier): JsonResponse
    {
        $this->network->accept($this->fiche($request, $courier), $request->user());

        return $this->show($request);
    }

    public function decline(Request $request, int $courier): JsonResponse
    {
        $this->network->decline($this->fiche($request, $courier), $request->user());

        return $this->show($request);
    }

    public function leave(Request $request, int $courier): JsonResponse
    {
        $this->network->leave($this->fiche($request, $courier), $request->user());

        return $this->show($request);
    }

    /** Ouvrir l'application livreur (adresse de sa boutique d'accueil, sans se reconnecter) */
    public function open(Request $request, LoginHandoff $handoff): JsonResponse
    {
        $url = $this->appUrl($request->user(), $handoff) ?? throw new BusinessRuleException('Aucune boutique ne vous a encore ajouté.');

        return response()->json(['data' => ['redirect' => $url]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnecté.']);
    }

    private function appUrl(User $user, LoginHandoff $handoff): ?string
    {
        $home = SharedCouriers::homeOf($user->id);

        return $home ? $handoff->url($user, $home, '/livreur') : null;
    }

    private function fiche(Request $request, int $id): Courier
    {
        return Courier::withoutGlobalScope(StoreScope::class)->with('store')->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
