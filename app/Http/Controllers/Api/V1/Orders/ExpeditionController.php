<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\Expeditions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expédition vers une autre ville, côté vendeur : dépôt à la gare noté à la main (sans livreur dans l'application),
 * photo du reçu, colis récupéré par le client.
 */
class ExpeditionController extends Controller
{
    public function __construct(private readonly Expeditions $expeditions) {}

    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:60'],
            'fee' => ['required', 'integer', 'min:0', 'max:1000000'],
            'ticket' => ['nullable', 'string', 'max:60'],
            'receipt' => ['nullable', 'file', 'image', 'max:8192'],
        ], [], ['company' => 'compagnie', 'fee' => 'frais d\'expédition', 'ticket' => 'n° de ticket', 'receipt' => 'photo du reçu']);

        $model = $this->expeditions->record(Order::findOrFail($order), $data, $request->file('receipt'), $request->user());

        return response()->json(['data' => OrderResource::make(OrderResource::loadDetail($model))->detailed()->resolve()]);
    }

    public function collected(Request $request, int $order): JsonResponse
    {
        $model = $this->expeditions->collected(Order::findOrFail($order), $request->user());

        return response()->json(['data' => OrderResource::make(OrderResource::loadDetail($model->fresh()))->detailed()->resolve()]);
    }

    public function receipt(int $order): StreamedResponse
    {
        $model = Order::findOrFail($order);
        abort_unless($model->expedition_receipt_path && Storage::disk('local')->exists($model->expedition_receipt_path), 404);

        return Storage::disk('local')->response($model->expedition_receipt_path);
    }
}
