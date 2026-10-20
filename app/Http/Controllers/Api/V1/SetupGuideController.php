<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Accounts\SetupGuide;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guide de démarrage : étapes de la personne connectée (selon son rôle), avancement, étapes passées.
 */
class SetupGuideController extends Controller
{
    public function show(Request $request, SetupGuide $guide): JsonResponse
    {
        return response()->json(['data' => $guide->forUser($this->currentStore(), $request->user())]);
    }

    public function skip(Request $request, SetupGuide $guide, string $step): JsonResponse
    {
        $data = $request->validate(['skipped' => ['required', 'boolean']]);
        $store = $this->currentStore();
        $guide->skip($store, $request->user(), $step, $data['skipped']);

        return response()->json(['data' => $guide->forUser($store->fresh(), $request->user())]);
    }
}
