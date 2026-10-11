<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StockLocation;
use App\Services\Shipping\ShipmentDesk;
use App\Services\Shipping\ShipmentPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Expéditions : devis des transporteurs, demande de course, annulation, statut manuel, suivi.
 */
class ShipmentController extends Controller
{
    public function __construct(private readonly ShipmentDesk $desk) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', 'max:30'], 'carrier_id' => ['nullable', 'integer']]);

        $shipments = Shipment::with(['order.store', 'connection', 'origin'])
            ->when($request->query('status') === 'active', fn ($q) => $q->whereNotIn('status', ['delivered', 'returned', 'cancelled', 'rejected', 'lost']))
            ->when($request->query('status') === 'problems', fn ($q) => $q->whereIn('status', ['rejected', 'failed', 'rescheduled', 'returning', 'lost']))
            ->when($request->filled('carrier_id'), fn ($q) => $q->where('carrier_connection_id', $request->integer('carrier_id')))
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($shipments->items())->map(fn (Shipment $s) => ShipmentPresenter::present($s, false) + [
                'customer_name' => $s->order->shipping_name ?? $s->order->customer_name,
                'place' => $s->order->shipping_place_label,
            ]),
            'meta' => ['current_page' => $shipments->currentPage(), 'last_page' => $shipments->lastPage(), 'total' => $shipments->total()],
        ]);
    }

    public function quotes(int $order): JsonResponse
    {
        $model = Order::with('items.product')->findOrFail($order);

        return response()->json([
            'data' => $this->desk->quotes($model),
            // Départ possible : emplacements actifs (celui de la commande en premier)
            'locations' => StockLocation::where('is_active', true)->orderByRaw('id = ? desc', [(int) $model->location_id])->orderBy('priority')->get(['id', 'name', 'type', 'carrier_connection_id']),
            'location_id' => $model->location_id,
        ]);
    }

    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'carrier_connection_id' => ['required', 'integer'],
            'origin_location_id' => ['nullable', 'integer'],
            'fee_payer' => ['nullable', Rule::in(['store', 'customer'])],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['carrier_connection_id' => 'transporteur']);

        $model = Order::findOrFail($order);
        $connection = CarrierConnection::findOrFail($data['carrier_connection_id']);
        $shipment = $this->desk->create($model, $connection, $data, $request->user());

        return response()->json(['data' => $this->present($shipment)], 201);
    }

    public function cancel(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $model = $this->desk->cancel(Shipment::findOrFail($shipment), $data['reason'] ?? null, $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function status(Request $request, int $shipment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, ShipmentStatus::manual()))],
            'note' => ['nullable', 'string', 'max:255'],
            'collected_amount' => ['nullable', 'integer', 'min:0'],
        ]);
        $model = $this->desk->updateManually(Shipment::findOrFail($shipment), ShipmentStatus::from($data['status']), $data['note'] ?? null, $data['collected_amount'] ?? null, $request->user());

        return response()->json(['data' => $this->present($model)]);
    }

    public function refresh(int $shipment): JsonResponse
    {
        return response()->json(['data' => $this->present($this->desk->refresh(Shipment::findOrFail($shipment)))]);
    }

    /**
     * Message WhatsApp pour un transporteur sans API.
     */
    public function message(int $shipment): JsonResponse
    {
        $model = Shipment::with(['order.store', 'order.items', 'origin', 'connection'])->findOrFail($shipment);
        $text = $this->desk->requestMessage($model);
        $phone = $model->connection?->contact_phone;

        return response()->json(['data' => [
            'text' => $text,
            'whatsapp_url' => $phone ? 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($text) : null,
        ]]);
    }

    private function present(Shipment $shipment): array
    {
        return ShipmentPresenter::present($shipment->load(['order.store', 'connection', 'events', 'origin']));
    }
}
