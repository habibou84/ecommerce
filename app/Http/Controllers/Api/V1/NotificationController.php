<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications internes de la personne connectée, pour la boutique de l'adresse.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->notifications()->where('store_id', $this->currentStore()->id);

        return response()->json([
            'data' => (clone $query)->limit(30)->get()->map(fn ($n) => $n->data + [
                'id' => $n->id,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'unread' => (clone $query)->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['sometimes', 'array'], 'ids.*' => ['string']]);

        $request->user()->unreadNotifications()->where('store_id', $this->currentStore()->id)
            ->when(isset($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Lu.']);
    }
}
