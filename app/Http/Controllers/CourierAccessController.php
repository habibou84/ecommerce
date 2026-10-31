<?php

namespace App\Http\Controllers;

use App\Services\Couriers\CourierAccess;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lien d'accès envoyé au livreur sur WhatsApp : connexion sans mot de passe, puis ses courses.
 */
class CourierAccessController extends Controller
{
    public function __invoke(Request $request, string $token, CourierAccess $access, Tenancy $tenancy): RedirectResponse
    {
        [$user, $error] = $access->consume($token, $tenancy->store());
        if ($user === null) {
            return redirect('/livreur/connexion?lien='.$error);
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect('/livreur');
    }
}
