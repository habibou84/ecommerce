<?php

namespace App\Http\Controllers\Api\V1\Couriers;

use App\Http\Controllers\Controller;
use App\Models\CashCollection;
use App\Models\Courier;
use App\Models\CourierRemittance;
use App\Services\Couriers\CashDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Caisse : espèces détenues par les livreurs, versements, écarts.
 */
class CashController extends Controller
{
    public function __construct(private readonly CashDesk $cash) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->cash->summary($this->currentStore()),
            'remittances' => CourierRemittance::with(['courier:id,name', 'receiver:id,name'])->latest('received_at')->limit(50)->get()
                ->map(fn (CourierRemittance $r) => [
                    'id' => $r->id,
                    'courier' => $r->courier?->name,
                    'expected_amount' => $r->expected_amount,
                    'received_amount' => $r->received_amount,
                    'difference' => $r->difference,
                    'method' => $r->method,
                    'received_by' => $r->receiver?->name,
                    'note' => $r->note,
                    'received_at' => $r->received_at->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Encaissements en cours d'un livreur (commande par commande).
     */
    public function collections(int $courier): JsonResponse
    {
        $model = Courier::findOrFail($courier);

        return response()->json(['data' => CashCollection::with('shipment.order.store')->where('courier_id', $model->id)
            ->whereNull('remittance_id')->where('method', 'cash')->orderBy('collected_at')->get()
            ->map(fn (CashCollection $c) => [
                'id' => $c->id,
                'amount' => $c->amount,
                'order_id' => $c->shipment->order_id,
                'reference' => $c->shipment->order->reference(),
                'customer' => $c->shipment->order->shipping_name ?? $c->shipment->order->customer_name,
                'collected_at' => $c->collected_at->toIso8601String(),
            ])]);
    }

    public function remit(Request $request, int $courier): JsonResponse
    {
        $data = $request->validate([
            'received_amount' => ['required', 'integer', 'min:0'],
            'method' => ['required', Rule::in(['cash', 'wave', 'orange_money', 'mtn_momo', 'moov_money'])],
            'note' => ['nullable', 'string', 'max:255'],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['integer'],
        ], [], ['received_amount' => 'montant reçu']);

        $remittance = $this->cash->remit(Courier::findOrFail($courier), $data['received_amount'], $data['method'], $data['note'] ?? null, $request->user(), $data['collection_ids'] ?? null);

        return response()->json(['data' => [
            'id' => $remittance->id,
            'expected_amount' => $remittance->expected_amount,
            'received_amount' => $remittance->received_amount,
            'difference' => $remittance->difference,
        ]], 201);
    }
}
