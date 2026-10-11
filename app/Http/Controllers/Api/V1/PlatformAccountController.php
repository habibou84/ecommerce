<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\Accounts\LoginHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Jibiat\Socle\PhoneNumber;

/**
 * Connexion sur jibiat-marchand.com pour qui ne connaît plus l'adresse de sa boutique : la session ouverte
 * ici sert seulement à lister ses boutiques et à rejoindre l'une d'elles.
 */
class PlatformAccountController extends Controller
{
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

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return $this->stores($request);
    }

    public function stores(Request $request): JsonResponse
    {
        $stores = $request->user()->stores()->wherePivot('status', 'active')->orderBy('name')->get()
            ->map(fn (Store $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'url' => $s->url(),
                'is_open' => $s->isOpen(),
                'status_label' => $s->status->label(),
            ]);

        return response()->json(['data' => ['name' => $request->user()->name, 'stores' => $stores]]);
    }

    public function open(Request $request, int $store, LoginHandoff $handoff): JsonResponse
    {
        $target = Store::findOrFail($store);
        $user = $request->user();

        abort_unless($target->isOpen() && $user->activeMembershipIn($target) !== null, 403, 'Vous n\'avez pas accès à cette boutique.');

        return response()->json(['redirect' => $handoff->url($user, $target)]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnecté.']);
    }
}
