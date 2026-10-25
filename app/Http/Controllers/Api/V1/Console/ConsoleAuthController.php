<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Enums\VerificationPurpose;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use App\Notifications\ConsoleLoginAlert;
use App\Services\Accounts\ConsoleEmailCode;
use App\Services\Accounts\PhoneVerifier;
use App\Services\Billing\BillingSettings;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Connexion à la console (admin.jibiat-marchand.com) : mot de passe, puis un code reçu par e-mail (adresse de
 * l'administrateur, réglée dans Mon compte). Sans adresse, si l'e-mail ne part pas ou à la demande : preuve du numéro
 * par WhatsApp (message envoyé au numéro du support) ou, à défaut, par code SMS.
 */
class ConsoleAuthController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier, private readonly ConsoleEmailCode $emailCodes) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:100'], 'channel' => ['nullable', 'in:email,phone']]);
        $user = User::where('phone', PhoneNumber::normalize($request->string('phone')))->first();

        if ($user === null || ! $user->is_super_admin || ! $user->isActive() || ! Hash::check($request->string('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['phone' => 'Numéro ou mot de passe incorrect.']);
        }

        if ($request->input('channel') !== 'phone' && ($sent = $this->emailCodes->start($user)) !== null) {
            return response()->json(['method' => 'email', 'token' => $sent['token'], 'email' => $sent['email']], 202);
        }

        [$verification, $token, $demoCode] = $this->verifier->start(VerificationPurpose::ConsoleLogin, $user->phone, user: $user, ip: $request->ip());

        return response()->json(VerificationController::pending($verification, $token, $demoCode, $this->verifier), 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string'], 'code' => ['required', 'string', 'max:10']]);
        $verification = $this->verifier->find($request->string('token'));
        if ($verification === null || $verification->purpose !== VerificationPurpose::ConsoleLogin || $verification->user === null) {
            throw ValidationException::withMessages(['code' => 'Cette demande a expiré. Recommencez.']);
        }

        $this->verifier->check($verification, $request->string('code'));
        $this->verifier->complete($verification);
        self::open($request, $verification->user);

        return $this->me($request);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:10']]);
        $user = $this->emailCodes->verify($request->string('token'), $request->string('code'));
        if ($user === null || ! $user->is_super_admin || ! $user->isActive()) {
            throw ValidationException::withMessages(['code' => 'Code incorrect ou expiré.']);
        }
        self::open($request, $user);

        return $this->me($request);
    }

    /**
     * Mon compte : adresse e-mail du code de connexion et mot de passe, après le mot de passe actuel.
     */
    public function updateAccount(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:10', 'max:100', 'confirmed'],
        ], [], ['current_password' => 'mot de passe actuel', 'email' => 'adresse e-mail', 'password' => 'nouveau mot de passe']);
        if (! Hash::check($data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mot de passe incorrect.']);
        }
        $user->forceFill(['email' => $data['email'] ?? null] + (filled($data['password'] ?? null) ? ['password' => Hash::make($data['password'])] : []))->save();
        if (filled($data['password'] ?? null)) {
            // Les autres sessions se ferment (AuthenticateSession) ; celle-ci reste ouverte
            Auth::guard('web')->setUser($user);
            $request->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));
        }
        AdminAction::create(['admin_id' => $user->id, 'action' => 'console_account', 'data' => ['email' => filled($data['email'] ?? null), 'password_changed' => filled($data['password'] ?? null)], 'ip' => $request->ip()]);

        return $this->me($request);
    }

    /**
     * Code d'essai à l'adresse enregistrée, pour vérifier que les e-mails arrivent avant de s'en servir pour se connecter.
     */
    public function testEmail(Request $request): JsonResponse
    {
        $sent = $this->emailCodes->start($request->user());

        return response()->json(['data' => ['ok' => $sent !== null, 'detail' => $sent !== null
            ? "E-mail envoyé à {$sent['email']}. Regardez aussi dans les spams."
            : (blank($request->user()->email) ? 'Aucune adresse enregistrée.' : 'L\'e-mail n\'est pas parti : vérifiez l\'envoi des e-mails (Paiements › Envoi des e-mails).')]]);
    }

    /**
     * Numéro prouvé (code SMS ou message WhatsApp) ou code reçu par e-mail : ouvre la session de la console.
     */
    public static function open(Request $request, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('console_admin', $user->id);
        // Session de la console limitée dans le temps (EnsureSuperAdmin) : nouveau code chaque jour
        $request->session()->put('console_since', now()->timestamp);
        $user->forceFill(['last_login_at' => now()])->save();
        AdminAction::create(['admin_id' => $user->id, 'action' => 'console_login', 'data' => ['device' => mb_substr((string) $request->userAgent(), 0, 190)], 'ip' => $request->ip()]);
        self::alert($request, $user);
    }

    /**
     * E-mail « nouvelle connexion » à l'adresse de l'administrateur (si l'envoi des e-mails est réglé).
     */
    private static function alert(Request $request, User $user): void
    {
        $mailer = app(BillingSettings::class)->mailer();
        if (blank($user->email) || $mailer === null) {
            return;
        }
        try {
            Notification::route('mail', $user->email)->notifyNow(new ConsoleLoginAlert(
                now()->timezone('Africa/Abidjan')->locale('fr')->translatedFormat('j F Y à H:i'), $request->ip(),
                mb_substr((string) $request->userAgent(), 0, 190) ?: null, Tenancy::consoleUrl('/console/compte'), $mailer['mailer'], $mailer['from'],
            ));
        } catch (Throwable $e) {
            Log::warning('Alerte de connexion à la console non envoyée', ['user' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'phone' => PhoneNumber::display($user->phone), 'email' => $user->email]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnecté.']);
    }
}
