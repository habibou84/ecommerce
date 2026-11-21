<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Models\CarrierReturnHandover;
use App\Services\Shipping\ReturnHandovers;
use Illuminate\Http\JsonResponse;

/**
 * Remise des retours par un livreur de Jibiat (contrat socle 1.14) : bandeau de l'accueil et des commandes,
 * « Tout reçu » ou « Il en manque ».
 */
class ReturnHandoverController extends Controller
{
    public function __construct(private readonly ReturnHandovers $handovers) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->handovers->open($this->currentStore())->map(fn ($h) => $this->present($h))->values()]);
    }

    public function confirm(int $handover): JsonResponse
    {
        return $this->answer($handover, true);
    }

    public function dispute(int $handover): JsonResponse
    {
        return $this->answer($handover, false);
    }

    private function answer(int $id, bool $allReceived): JsonResponse
    {
        $handover = CarrierReturnHandover::findOrFail($id);

        return response()->json(['data' => $this->present($this->handovers->answer($handover, $allReceived))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CarrierReturnHandover $h): array
    {
        return [
            'id' => $h->id,
            'reference' => $h->reference,
            'status' => $h->status,
            'carrier' => $h->connection?->name,
            'courier_name' => $h->courier_name,
            'received_by_name' => $h->received_by_name,
            'expires_at' => $h->expires_at?->toIso8601String(),
            'handed_at' => $h->handed_at?->toIso8601String(),
            'confirmed_at' => $h->confirmed_at?->toIso8601String(),
            'disputed_at' => $h->disputed_at?->toIso8601String(),
            'parcels' => $this->handovers->parcels($h),
        ];
    }
}
