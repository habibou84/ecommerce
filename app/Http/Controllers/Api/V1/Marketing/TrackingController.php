<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suivi publicitaire de la boutique en ligne : pixels Meta et TikTok, Google Analytics, API Conversions de Meta.
 */
class TrackingController extends Controller
{
    public function show(): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => [
            'meta_pixel_id' => $store->marketingId('meta_pixel_id'),
            'tiktok_pixel_id' => $store->marketingId('tiktok_pixel_id'),
            'ga4_id' => $store->marketingId('ga4_id'),
            'has_capi_token' => filled($store->meta_capi_token),
            // Achats envoyés par le serveur ces 30 derniers jours
            'conversions_sent' => Order::where('conversion_sent_at', '>=', now()->subDays(30))->count(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'meta_pixel_id' => ['nullable', 'regex:/^\d{6,20}$/'],
            'tiktok_pixel_id' => ['nullable', 'regex:/^[A-Z0-9]{10,30}$/'],
            'ga4_id' => ['nullable', 'regex:/^G-[A-Z0-9]{4,20}$/'],
            'meta_capi_token' => ['nullable', 'string', 'max:500'],
            'remove_capi_token' => ['sometimes', 'boolean'],
        ], [
            'meta_pixel_id.regex' => 'L\'identifiant du pixel Meta ne contient que des chiffres.',
            'tiktok_pixel_id.regex' => 'Identifiant de pixel TikTok invalide (lettres majuscules et chiffres).',
            'ga4_id.regex' => 'L\'identifiant Google Analytics commence par G-.',
        ]);

        $store = $this->currentStore();
        $store->marketing = array_filter([
            'meta_pixel_id' => $data['meta_pixel_id'] ?? null,
            'tiktok_pixel_id' => $data['tiktok_pixel_id'] ?? null,
            'ga4_id' => $data['ga4_id'] ?? null,
        ]) ?: null;
        if ($request->boolean('remove_capi_token')) {
            $store->meta_capi_token = null;
        } elseif (filled($data['meta_capi_token'] ?? null)) {
            $store->meta_capi_token = $data['meta_capi_token'];
        }
        $store->save();

        return $this->show();
    }
}
