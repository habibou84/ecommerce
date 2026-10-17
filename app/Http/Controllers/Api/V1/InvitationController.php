<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\User;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Accounts\TeamStores;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

class InvitationController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy, private readonly Invitations $invitations) {}

    public function index(): JsonResponse
    {
        $invitations = StoreInvitation::pending()->with('inviter:id,name')->latest()->get()
            ->map(fn (StoreInvitation $i) => $this->present($i));

        return response()->json(['data' => $invitations]);
    }

    public function store(Request $request, TeamStores $team): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', new PhoneRule],
            'role' => ['required', Rule::in(Role::invitableValues())],
            'name' => ['nullable', 'string', 'max:80'],
            // Autres boutiques de l'adresse : [id de la boutique => rôle]
            'stores' => ['nullable', 'array'],
            'stores.*' => ['string'],
        ], [], ['phone' => 'numéro de téléphone', 'role' => 'rôle', 'name' => 'nom', 'stores' => 'boutiques']);

        $store = $this->tenancy->store();
        $extras = $team->extras($request->user(), $store, $data['stores'] ?? []);
        [$invitation, $link] = $this->invitations->invite($store, $request->user(), $data['phone'], Role::from($data['role']), $data['name'] ?? null, $extras);

        $names = collect([$store->name])->concat(Store::whereIn('id', array_column($extras, 'store_id'))->pluck('name'));
        $text = 'Bonjour'.($invitation->name ? ' '.$invitation->name : '').", vous êtes invité(e) à rejoindre l'équipe de "
            .$names->join(', ', ' et ')." sur Jibiat Marchand comme {$invitation->role->label()}. Ouvrez ce lien : {$link}";

        return response()->json([
            'data' => $this->present($invitation),
            'link' => $link,
            // Lien WhatsApp prérempli à envoyer par le marchand (aucun coût d'envoi)
            'whatsapp_url' => 'https://wa.me/'.ltrim($invitation->phone, '+').'?text='.rawurlencode($text),
            'message' => $text,
        ], 201);
    }

    public function destroy(int $invitation): JsonResponse
    {
        StoreInvitation::pending()->findOrFail($invitation)->forceFill(['revoked_at' => now()])->save();

        return response()->json(['message' => 'Invitation annulée.']);
    }

    /**
     * Page d'invitation (personne non connectée).
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->findPending($token);

        return response()->json(['data' => [
            'store' => $this->tenancy->store()->name,
            'name' => $invitation->name,
            'phone' => PhoneNumber::display($invitation->phone),
            'role' => $invitation->role->label(),
            'inviter' => $invitation->inviter?->name,
            // Autres boutiques rejointes en même temps
            'other_stores' => $this->otherStores($invitation),
            // La personne a déjà un compte : son mot de passe suffit
            'has_account' => User::where('phone', $invitation->phone)->exists(),
        ]]);
    }

    public function accept(Request $request, string $token, PhoneVerifier $verifier): JsonResponse
    {
        $invitation = $this->findPending($token);

        if (User::where('phone', $invitation->phone)->exists()) {
            $request->validate(['password' => ['required', 'string', 'max:100']]);
            $user = $this->invitations->acceptWithPassword($invitation, $request->string('password'));

            Auth::guard('web')->login($user, remember: true);
            $request->session()->regenerate();

            return response()->json(['redirect' => $this->invitations->homeFor($user, $invitation->store)]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
        ], [], ['name' => 'votre nom', 'password' => 'mot de passe']);

        [$verification, $verificationToken, $code] = $this->invitations->startNewAccount($invitation, $data['name'], $data['password'], $request->ip());

        return response()->json(VerificationController::pending($verification, $verificationToken, $code, $verifier), 202);
    }

    private function findPending(string $token): StoreInvitation
    {
        $invitation = $this->invitations->find($this->tenancy->store(), $token);
        abort_if($invitation === null || ! $invitation->isPending(), 404, 'Cette invitation n\'est plus valable.');

        return $invitation;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StoreInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'name' => $invitation->name,
            'phone' => PhoneNumber::display($invitation->phone),
            'role' => $invitation->role->value,
            'role_label' => $invitation->role->label(),
            'inviter' => $invitation->inviter?->name,
            'other_stores' => $this->otherStores($invitation),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    /**
     * @return list<array{name: string, role: string}>
     */
    private function otherStores(StoreInvitation $invitation): array
    {
        $names = Store::whereIn('id', array_column($invitation->extra_stores ?? [], 'store_id'))->pluck('name', 'id');

        return collect($invitation->extra_stores ?? [])->filter(fn ($e) => $names->has($e['store_id']))
            ->map(fn ($e) => ['name' => $names[$e['store_id']], 'role' => Role::from($e['role'])->label()])->values()->all();
    }
}
