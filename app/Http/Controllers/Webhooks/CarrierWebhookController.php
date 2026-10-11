<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Services\Shipping\CarrierEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réception des webhooks d'une entreprise de livraison : une adresse par connexion
 * (api.jibiat-marchand.com/webhooks/carriers/{uuid}), signature vérifiée avec le secret de la connexion.
 */
class CarrierWebhookController extends Controller
{
    public function __invoke(Request $request, string $uuid, CarrierEvents $events): JsonResponse
    {
        $connection = CarrierConnection::withoutGlobalScopes()->with('store')->where('webhook_uuid', $uuid)->where('status', 'active')->first();
        if ($connection === null) {
            return response()->json(['message' => 'Adresse inconnue.'], 404);
        }

        $status = $events->receive($connection, $request);

        return response()->json(['message' => $status === 200 ? 'OK' : 'Refusé.'], $status);
    }
}
