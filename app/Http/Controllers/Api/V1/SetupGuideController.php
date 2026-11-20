<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Accounts\SetupGuide;
use App\Services\Accounts\StoreRoles;
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

    /**
     * Assistant du premier accès terminé ou remis à plus tard.
     */
    public function finishWelcome(Request $request, SetupGuide $guide, StoreRoles $roles): JsonResponse
    {
        $store = $this->currentStore();
        abort_unless($roles->can($request->user(), $store, Permission::StoreManage), 403);
        $guide->finishWelcome($store);

        return response()->json(['data' => $guide->welcome($store->fresh(), $request->user())]);
    }

    public function skip(Request $request, SetupGuide $guide, string $step): JsonResponse
    {
        $data = $request->validate(['skipped' => ['required', 'boolean']]);
        $store = $this->currentStore();
        $guide->skip($store, $request->user(), $step, $data['skipped']);

        return response()->json(['data' => $guide->forUser($store->fresh(), $request->user())]);
    }
}
