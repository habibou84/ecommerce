<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Billing\Billing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications du prestataire de paiement de la plateforme (abonnements des boutiques).
 */
class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, Billing $billing): JsonResponse
    {
        // CinetPay vérifie l'adresse par un GET
        if ($request->isMethod('get')) {
            return response()->json(['message' => 'OK']);
        }

        return $billing->webhook($request) ? response()->json(['message' => 'OK']) : response()->json(['message' => 'Signature invalide.'], 401);
    }
}
