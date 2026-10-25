<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Services\Mail\GoogleMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Retour de Google après « Se connecter avec Google » (console › Paiements › Envoi des e-mails) : seulement pour
 * l'administrateur connecté qui a lancé la demande (jeton « state » de sa session).
 */
class GmailCallbackController extends Controller
{
    public function __invoke(Request $request, GoogleMail $gmail): RedirectResponse
    {
        $user = Auth::guard('web')->user();
        $state = $request->session()->pull('gmail_state');
        if ($user === null || ! $user->is_super_admin || $request->session()->get('console_admin') !== $user->id
            || ! is_string($state) || ! hash_equals($state, (string) $request->query('state'))) {
            return redirect('/console/paiements?gmail=expire');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect('/console/paiements?gmail=annule');
        }

        try {
            $email = $gmail->connect((string) $request->query('code'));
        } catch (RuntimeException $e) {
            Log::warning('Connexion de Gmail refusée', ['error' => $e->getMessage()]);

            return redirect('/console/paiements?'.http_build_query(['gmail' => 'erreur', 'detail' => mb_substr($e->getMessage(), 0, 200)]));
        }
        AdminAction::create(['admin_id' => $user->id, 'action' => 'gmail_connected', 'data' => ['email' => $email], 'ip' => $request->ip()]);

        return redirect('/console/paiements?gmail=ok');
    }
}
