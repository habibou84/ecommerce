<?php

namespace App\Http\Controllers\Api\Partners;

use App\Http\Controllers\Controller;
use App\Services\Shipping\CarrierDirectConnect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jibiat\Socle\Contract\Contract;

/**
 * Décision d'une entreprise de Jibiat sur une connexion directe (contrat socle 1.12, connect-decision), requête
 * signée : compte ouvert avec la clé d'API, refus ou expiration. La même décision reçue deux fois est sans effet.
 */
class ConnectionController extends Controller
{
    public function store(Request $request, CarrierDirectConnect $direct): JsonResponse
    {
        $decision = $request->json()->all();
        $errors = Contract::validate('connect-decision', $decision);
        if ($errors !== []) {
            return response()->json(['message' => 'Décision invalide.', 'errors' => $errors], 422);
        }

        $carrierRequest = $direct->receive($decision);
        if ($carrierRequest === null) {
            return response()->json(['message' => 'Demande inconnue.'], 404);
        }

        return response()->json(['data' => ['request_id' => $carrierRequest->request_id, 'status' => $carrierRequest->status]]);
    }
}
