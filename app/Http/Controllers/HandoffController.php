<?php

namespace App\Http\Controllers;

use App\Services\Accounts\LoginHandoff;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Arrivée sur {slug}.jibiat-marchand.com avec un lien de connexion à usage unique (création de boutique,
 * choix de boutique, changement d'adresse).
 */
class HandoffController extends Controller
{
    public function __invoke(Request $request, LoginHandoff $handoff, Tenancy $tenancy): RedirectResponse
    {
        $result = $handoff->consume((string) $request->query('jeton'), $tenancy->store());

        if ($result === null) {
            return redirect('/gestion/connexion');
        }

        [$user, $redirect] = $result;
        if (! $user->isActive() || $user->activeMembershipIn($tenancy->store()) === null) {
            return redirect('/gestion/connexion');
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect(str_starts_with($redirect, '/') ? $redirect : '/gestion');
    }
}
