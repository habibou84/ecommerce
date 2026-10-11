<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Services\Stock\StockKeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(private readonly StockKeeper $keeper) {}

    public function index(): JsonResponse
    {
        $page = StockTransfer::with(['from:id,name', 'to:id,name'])->withSum('items', 'quantity')->latest('id')->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (StockTransfer $t) => $this->present($t) + ['units' => (int) $t->items_sum_quantity]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $transfer): JsonResponse
    {
        $model = StockTransfer::with(['from:id,name', 'to:id,name', 'items.variant.product:id,title'])->findOrFail($transfer);

        return response()->json(['data' => $this->present($model) + [
            'items' => $model->items->map(fn ($i) => [
                'variant_id' => $i->variant_id,
                'variant' => $i->variant?->fullTitle(),
                'quantity' => $i->quantity,
                'received_quantity' => $i->received_quantity,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer', 'different:from_location_id'],
            'reference' => ['nullable', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ], [], ['to_location_id' => 'destination', 'items' => 'articles', 'items.*.quantity' => 'quantité']);

        $from = StockLocation::where('is_active', true)->findOrFail($data['from_location_id']);
        $to = StockLocation::where('is_active', true)->findOrFail($data['to_location_id']);
        $variants = ProductVariant::with('product')->whereIn('id', collect($data['items'])->pluck('variant_id'))->get()->keyBy('id');

        $transfer = $this->keeper->sendTransfer($request->user(), $from, $to, collect($data['items'])->map(fn ($item) => [
            'variant' => $variants->get($item['variant_id']) ?? abort(404),
            'quantity' => (int) $item['quantity'],
        ])->all(), $data['reference'] ?? null);

        return response()->json(['data' => ['id' => $transfer->id]], 201);
    }

    public function receive(Request $request, int $transfer): JsonResponse
    {
        $data = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.received_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $received = collect($data['items'] ?? [])->mapWithKeys(fn ($i) => [$i['variant_id'] => $i['received_quantity']])->all();
        $this->keeper->receiveTransfer($request->user(), StockTransfer::findOrFail($transfer), $received);

        return response()->json(['message' => 'Transfert reçu.']);
    }

    public function cancel(Request $request, int $transfer): JsonResponse
    {
        $this->keeper->cancelTransfer($request->user(), StockTransfer::findOrFail($transfer));

        return response()->json(['message' => 'Transfert annulé.']);
    }

    private function present(StockTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'from' => $transfer->from?->name,
            'to' => $transfer->to?->name,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'reference' => $transfer->reference,
            'shipped_at' => $transfer->shipped_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
        ];
    }
}
