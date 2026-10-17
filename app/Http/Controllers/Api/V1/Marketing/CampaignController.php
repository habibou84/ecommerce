<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\Marketing\Campaigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Relances WhatsApp des anciens clients : brouillon, aperçu (nombre de clients, coût estimé), envoi, résultats.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly Campaigns $campaigns) {}

    public function index(): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json([
            'data' => Campaign::forStore($store->id)->latest()->limit(50)->get()->map(fn (Campaign $c) => $this->campaigns->present($store, $c)),
            'meta' => [
                'whatsapp_ready' => $this->campaigns->account($store) !== null,
                'message_price' => $this->campaigns->price(),
                'store_url' => $store->url('/'),
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->campaigns->preview($this->currentStore(), $this->audience($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore();
        $campaign = $this->campaigns->save($store, null, $this->validated($request), $request->user());

        return response()->json(['data' => $this->campaigns->present($store, $campaign)], 201);
    }

    public function update(Request $request, int $campaign): JsonResponse
    {
        $store = $this->currentStore();
        $model = Campaign::forStore($store->id)->findOrFail($campaign);

        return response()->json(['data' => $this->campaigns->present($store, $this->campaigns->save($store, $model, $this->validated($request), $request->user()))]);
    }

    public function send(int $campaign): JsonResponse
    {
        $store = $this->currentStore();
        $model = Campaign::forStore($store->id)->findOrFail($campaign);

        return response()->json(['data' => $this->campaigns->present($store, $this->campaigns->send($store, $model))]);
    }

    public function destroy(int $campaign): JsonResponse
    {
        Campaign::forStore($this->currentStore()->id)->where('status', 'draft')->findOrFail($campaign)->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function audience(Request $request): array
    {
        return $request->validate([
            'audience' => ['required', 'array'],
            'audience.type' => ['required', Rule::in(['inactive', 'product', 'all'])],
            'audience.days' => ['nullable', 'integer', 'min:7', 'max:730'],
            'audience.product_id' => ['nullable', 'integer'],
            'audience.include_buyers' => ['sometimes', 'boolean'],
        ], [], ['audience.days' => 'nombre de jours', 'audience.product_id' => 'produit'])['audience'];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:300'],
            'link' => ['nullable', 'url:https', 'max:500'],
        ], [], ['title' => 'nom', 'message' => 'message', 'link' => 'lien']) + ['audience' => $this->audience($request)];
    }
}
