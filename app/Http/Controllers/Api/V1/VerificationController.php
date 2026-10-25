<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\V1\Console\ConsoleAuthController;
use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\PasswordReset;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Accounts\StoreRoles;
use App\Services\Accounts\StoreSignup;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Jibiat\Socle\PhoneNumber;
use Spatie\Permission\PermissionRegistrar;

/**
 * Vérification en cours d'un numéro (création de boutique, invitation, mot de passe oublié, connexion) : état lu par
 * la page (le message WhatsApp arrive sur le serveur), code SMS en dernier recours, fin de l'action une fois le
 * numéro prouvé. La demande est désignée par le jeton secret remis au navigateur à son ouverture.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier, private readonly Tenancy $tenancy, private readonly StoreRoles $roles) {}

    /**
     * Réponse commune à l'ouverture d'une vérification.
     *
     * @return array<string, mixed>
     */
    public static function pending(PhoneVerification $verification, string $token, ?string $demoCode, PhoneVerifier $verifier): array
    {
        return [
            'verification' => ['token' => $token] + self::describe($verification, $verifier),
            // Développement uniquement (SMS non envoyés) : le code est affiché sur la page
            'demo_code' => $demoCode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(PhoneVerification $verification, PhoneVerifier $verifier): array
    {
        return [
            'purpose' => $verification->purpose->value,
            'phone' => PhoneNumber::display($verification->phone),
            'status' => match (true) {
                $verification->completed_at !== null => 'completed',
                $verification->isVerified() => 'verified',
                $verification->isExpired() => 'expired',
                default => 'pending',
            },
            'verified_via' => $verification->verified_via,
            'expires_at' => $verification->expires_at->toIso8601String(),
            // Message WhatsApp prérempli vers le numéro du support (null : aucun numéro relié, SMS seulement)
            'whatsapp' => $verifier->whatsappInstructions($verification),
            'sms' => [
                'available' => $verifier->smsOffered($verification),
                'sent' => $verification->sms_count > 0,
                'resend_in' => $verifier->smsWaitSeconds($verification),
            ],
            // Compatibilité : première version de la page
            'resend_in' => $verifier->smsWaitSeconds($verification),
        ];
    }

    /**
     * État lu par la page toutes les quelques secondes : dès que le message WhatsApp est arrivé, l'action se termine
     * (sauf un nouveau mot de passe à saisir : la page le demande puis appelle « confirm »).
     */
    public function show(Request $request, string $token, StoreSignup $signup, Invitations $invitations, LoginHandoff $handoff): JsonResponse
    {
        $verification = $this->findForHere($token);
        $state = ['verification' => self::describe($verification, $this->verifier)];

        if ($verification->isVerified() && $verification->completed_at === null && ! $verification->isExpired()
            && $verification->purpose !== VerificationPurpose::PasswordReset) {
            return $this->finish($request, $verification, null, $signup, $invitations, null, $handoff);
        }

        return response()->json($state);
    }

    /**
     * Code SMS (ou nouveau code) : seulement quand le SMS est proposé.
     */
    public function resend(string $token): JsonResponse
    {
        $verification = $this->findForHere($token);
        $code = $this->verifier->sendSms($verification);

        return response()->json([
            'message' => 'Code envoyé par SMS.',
            'verification' => self::describe($verification->refresh(), $this->verifier),
            'resend_in' => $this->verifier->smsWaitSeconds($verification),
            'demo_code' => $code,
        ]);
    }

    public function confirm(Request $request, string $token, StoreSignup $signup, Invitations $invitations, PasswordReset $reset, LoginHandoff $handoff): JsonResponse
    {
        $verification = $this->findForHere($token);

        $request->validate([
            // Numéro déjà prouvé par WhatsApp : pas de code à saisir
            'code' => [$verification->isVerified() ? 'nullable' : 'required', 'string', 'max:10'],
            'password' => [$verification->purpose === VerificationPurpose::PasswordReset ? 'required' : 'nullable', 'string', 'min:8', 'max:100'],
        ], [], ['code' => 'code', 'password' => 'nouveau mot de passe']);

        if (! $verification->isVerified()) {
            $this->verifier->check($verification, $request->string('code'));
        }

        return $this->finish($request, $verification, $request->string('password')->toString() ?: null, $signup, $invitations, $reset, $handoff);
    }

    /**
     * Numéro prouvé : l'action demandée, une seule fois.
     */
    private function finish(Request $request, PhoneVerification $verification, ?string $password, StoreSignup $signup, Invitations $invitations, ?PasswordReset $reset, LoginHandoff $handoff): JsonResponse
    {
        if ($verification->completed_at !== null) {
            throw new BusinessRuleException('Cette demande a déjà été utilisée. Recommencez.');
        }

        return match ($verification->purpose) {
            VerificationPurpose::Signup => (function () use ($signup, $verification, $handoff) {
                [$user, $store] = $signup->complete($verification);

                return response()->json(['status' => 'completed', 'redirect' => $handoff->url($user, $store, '/gestion?bienvenue=1')], 201);
            })(),

            VerificationPurpose::Invitation => (function () use ($invitations, $verification, $request) {
                $user = $invitations->completeNewAccount($verification);
                $this->login($request, $user);

                return response()->json(['status' => 'completed', 'redirect' => $invitations->homeFor($user, $verification->store)]);
            })(),

            VerificationPurpose::PasswordReset => (function () use ($reset, $verification, $request, $password) {
                $user = $reset->complete($verification, $password);

                // Sur une boutique dont la personne fait partie : connectée directement
                $store = $this->tenancy->store();
                if ($store !== null && $user->isActive() && $user->activeMembershipIn($store) !== null) {
                    $this->login($request, $user);

                    return response()->json(['status' => 'completed', 'message' => 'Mot de passe changé.', 'redirect' => '/gestion']);
                }

                return response()->json(['status' => 'completed', 'message' => 'Mot de passe changé. Connectez-vous avec le nouveau mot de passe.', 'redirect' => null]);
            })(),

            VerificationPurpose::ConsoleLogin => (function () use ($verification, $request) {
                $user = $verification->user;
                if ($user === null || ! $user->is_super_admin || ! $user->isActive()) {
                    throw new BusinessRuleException('Ce compte ne peut pas ouvrir la console.');
                }
                $this->verifier->complete($verification);
                ConsoleAuthController::open($request, $user);

                return response()->json(['status' => 'completed', 'redirect' => '/console']);
            })(),

            VerificationPurpose::Login => $this->finishLogin($request, $verification),
        };
    }

    /**
     * Connexion par WhatsApp : à la gestion de la boutique de l'adresse (ou d'une boutique sœur), ou sur la
     * plateforme pour retrouver ses boutiques.
     */
    private function finishLogin(Request $request, PhoneVerification $verification): JsonResponse
    {
        $user = $verification->user?->fresh();
        if ($user === null || ! $user->isActive()) {
            throw new BusinessRuleException('Compte suspendu.');
        }
        $this->verifier->complete($verification);

        $store = $this->tenancy->store();
        if ($store === null) {
            // Plateforme : la session sert à lister ses boutiques
            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return response()->json(['status' => 'completed', 'redirect' => null]);
        }

        if ($user->activeMembershipIn($store) === null) {
            $other = $store->addressStores()->first(fn (Store $s) => $user->activeMembershipIn($s) !== null);
            if ($other === null) {
                throw new BusinessRuleException('Ce compte ne fait pas partie de l\'équipe de '.$store->name.'.');
            }
            $this->tenancy->setStore($other);
            app(PermissionRegistrar::class)->setPermissionsTeamId($other->id);
            $store = $other;
        }
        $this->login($request, $user);

        return response()->json(['status' => 'completed', 'redirect' => $this->roles->roleOf($user, $store) === Role::Courier ? '/livreur' : '/gestion']);
    }

    /**
     * Les vérifications d'une boutique ne se terminent que sur son adresse, la création de boutique que sur la plateforme,
     * la connexion à la console que sur la console ; le mot de passe oublié sans boutique sur la plateforme ou la console.
     */
    private function findForHere(string $token): PhoneVerification
    {
        $verification = $this->verifier->find($token) ?? throw new BusinessRuleException('Cette demande n\'existe pas ou a expiré. Recommencez.');

        $here = match ($verification->purpose) {
            VerificationPurpose::Signup => $this->tenancy->zone() === Tenancy::PLATFORM,
            VerificationPurpose::Invitation => $this->tenancy->store()?->id === $verification->store_id,
            VerificationPurpose::ConsoleLogin => $this->tenancy->zone() === Tenancy::CONSOLE,
            // Mot de passe oublié sans boutique : sur la plateforme ou sur la console (nouvel administrateur, oubli)
            VerificationPurpose::PasswordReset => $verification->store_id === null
                ? in_array($this->tenancy->zone(), [Tenancy::PLATFORM, Tenancy::CONSOLE], true)
                : $this->tenancy->store()?->id === $verification->store_id,
            VerificationPurpose::Login => $verification->store_id === null
                ? $this->tenancy->zone() === Tenancy::PLATFORM
                : $this->tenancy->store()?->id === $verification->store_id,
        };

        abort_unless($here, 404);

        return $verification;
    }

    private function login(Request $request, User $user): void
    {
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
    }
}
