<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Couriers\CourierRoster;
use App\Services\Couriers\SharedCouriers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications internes de la personne connectée, pour la boutique de l'adresse.
 */
class NotificationController extends Controller
{
    public function index(Request $request, SharedCouriers $shared): JsonResponse
    {
        $query = $request->user()->notifications()->whereIn('store_id', $this->storeIds($request, $shared));

        return response()->json([
            'data' => (clone $query)->limit(30)->get()->map(fn ($n) => $n->data + [
                'id' => $n->id,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'unread' => (clone $query)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, SharedCouriers $shared): JsonResponse
    {
        $data = $request->validate(['ids' => ['sometimes', 'array'], 'ids.*' => ['string']]);

        $request->user()->unreadNotifications()->whereIn('store_id', $this->storeIds($request, $shared))
            ->when(isset($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Lu.']);
    }

    /**
     * La boutique de l'adresse ; pour un livreur, aussi ses autres boutiques (il n'y est que livreur : ce sont ses
     * missions), réunies dans son espace.
     *
     * @return list<int>
     */
    private function storeIds(Request $request, SharedCouriers $shared): array
    {
        $store = $this->currentStore();
        $courier = CourierRoster::of($request->user(), $store);

        return $courier ? $shared->ofPerson($courier)->pluck('store_id')->push($store->id)->unique()->values()->all() : [$store->id];
    }
}
