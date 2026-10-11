<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\PhoneVerification;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\LoginHandoff;
use App\Services\Accounts\PasswordReset;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Accounts\StoreSignup;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Jibiat\Socle\PhoneNumber;

/**
 * Code SMS d'une vérification en cours (création de boutique, invitation, mot de passe oublié).
 * La demande est désignée par le jeton secret remis au navigateur à son ouverture.
 */
class VerificationController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier, private readonly Tenancy $tenancy) {}

    /**
     * Réponse commune à l'ouverture d'une vérification.
     *
     * @return array<string, mixed>
     */
    public static function pending(PhoneVerification $verification, string $token, ?string $demoCode, PhoneVerifier $verifier): array
    {
        return [
            'verification' => [
                'token' => $token,
                'phone' => PhoneNumber::display($verification->phone),
                'expires_at' => $verification->expires_at->toIso8601String(),
                'resend_in' => $verifier->smsWaitSeconds($verification),
            ],
            // Développement uniquement (SMS non envoyés) : le code est affiché sur la page
            'demo_code' => $demoCode,
        ];
    }

    public function resend(string $token): JsonResponse
    {
        $verification = $this->findForHere($token);
        $code = $this->verifier->sendSms($verification);

        return response()->json([
            'message' => 'Nouveau code envoyé.',
            'resend_in' => $this->verifier->smsWaitSeconds($verification),
            'demo_code' => $code,
        ]);
    }

    public function confirm(Request $request, string $token, StoreSignup $signup, Invitations $invitations, PasswordReset $reset, LoginHandoff $handoff): JsonResponse
    {
        $verification = $this->findForHere($token);

        $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'password' => [$verification->purpose === VerificationPurpose::PasswordReset ? 'required' : 'nullable', 'string', 'min:8', 'max:100'],
        ], [], ['code' => 'code', 'password' => 'nouveau mot de passe']);

        $this->verifier->check($verification, $request->string('code'));

        return match ($verification->purpose) {
            VerificationPurpose::Signup => (function () use ($signup, $verification, $handoff) {
                [$user, $store] = $signup->complete($verification);

                return response()->json(['redirect' => $handoff->url($user, $store, '/gestion?bienvenue=1')], 201);
            })(),

            VerificationPurpose::Invitation => (function () use ($invitations, $verification, $request) {
                $user = $invitations->completeNewAccount($verification);
                $this->login($request, $user);

                return response()->json(['redirect' => $invitations->homeFor($user, $verification->store)]);
            })(),

            VerificationPurpose::PasswordReset => (function () use ($reset, $verification, $request) {
                $user = $reset->complete($verification, $request->string('password'));

                // Sur une boutique dont la personne fait partie : connectée directement
                $store = $this->tenancy->store();
                if ($store !== null && $user->isActive() && $user->activeMembershipIn($store) !== null) {
                    $this->login($request, $user);

                    return response()->json(['message' => 'Mot de passe changé.', 'redirect' => '/gestion']);
                }

                return response()->json(['message' => 'Mot de passe changé. Connectez-vous avec le nouveau mot de passe.', 'redirect' => null]);
            })(),
        };
    }

    /**
     * Les vérifications d'une boutique ne se terminent que sur son adresse, la création de boutique que sur la plateforme.
     */
    private function findForHere(string $token): PhoneVerification
    {
        $verification = $this->verifier->find($token) ?? throw new BusinessRuleException('Cette demande n\'existe pas ou a expiré. Recommencez.');

        $here = match ($verification->purpose) {
            VerificationPurpose::Signup => $this->tenancy->zone() === Tenancy::PLATFORM,
            VerificationPurpose::Invitation => $this->tenancy->store()?->id === $verification->store_id,
            VerificationPurpose::PasswordReset => $verification->store_id === null
                ? $this->tenancy->zone() === Tenancy::PLATFORM
                : $this->tenancy->store()?->id === $verification->store_id,
        };

        abort_unless($here, 404);

        return $verification;
    }

    private function login(Request $request, $user): void
    {
        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
    }
}
