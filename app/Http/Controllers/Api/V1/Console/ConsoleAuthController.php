<?php

namespace App\Http\Controllers\Api\V1\Console;

use App\Enums\VerificationPurpose;
use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\Accounts\PhoneVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;

/**
 * Connexion à la console (admin.jibiat-marchand.com) : mot de passe, puis code reçu par SMS.
 */
class ConsoleAuthController extends Controller
{
    public function __construct(private readonly PhoneVerifier $verifier) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:100']]);
        $user = User::where('phone', PhoneNumber::normalize($request->string('phone')))->first();

        if ($user === null || ! $user->is_super_admin || ! $user->isActive() || ! Hash::check($request->string('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['phone' => 'Numéro ou mot de passe incorrect.']);
        }

        [, $token, $demoCode] = $this->verifier->start(VerificationPurpose::ConsoleLogin, $user->phone, user: $user, ip: $request->ip());

        return response()->json(['verification' => ['token' => $token, 'phone' => PhoneNumber::display($user->phone)]] + ($demoCode ? ['demo_code' => $demoCode] : []), 202);
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
        $user = $verification->user;

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('console_admin', $user->id);
        $user->forceFill(['last_login_at' => now()])->save();
        AdminAction::create(['admin_id' => $user->id, 'action' => 'console_login', 'ip' => $request->ip()]);

        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'phone' => PhoneNumber::display($user->phone)]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnecté.']);
    }
}
