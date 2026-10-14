<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Téléphone où l'application est installée : son adresse de notification push est rattachée à la personne
 * connectée (et retirée à la déconnexion).
 */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:android,ios'],
        ]);

        // Un téléphone passé à une autre personne change de propriétaire
        DeviceToken::updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()->id,
            'platform' => $data['platform'],
            'last_seen_at' => now(),
        ]);

        return response()->json(['message' => 'Notifications activées sur ce téléphone.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:512']]);
        DeviceToken::where('user_id', $request->user()->id)->where('token', $request->input('token'))->delete();

        return response()->json(['message' => 'Notifications désactivées sur ce téléphone.']);
    }
}
