<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentAccount;
use App\Services\Payments\PaymentDesk;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications des prestataires de paiement : une adresse par compte (api.jibiat-marchand.com/webhooks/payments/{uuid}).
 * Une notification ne valide jamais seule un paiement : il est toujours contrôlé auprès du prestataire.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $uuid, PaymentDesk $payments, Tenancy $tenancy): JsonResponse
    {
        $account = PaymentAccount::withoutGlobalScopes()->with('store')->where('webhook_uuid', $uuid)->first();
        if ($account === null) {
            return response()->json(['message' => 'Adresse inconnue.'], 404);
        }
        // Vérification de disponibilité de l'adresse (CinetPay l'appelle en GET)
        if ($request->isMethod('get')) {
            return response()->json(['message' => 'OK']);
        }

        $tenancy->setStore($account->store);
        $status = $payments->webhook($account, $request);

        return response()->json(['message' => $status === 200 ? 'OK' : 'Refusé.'], $status);
    }
}
