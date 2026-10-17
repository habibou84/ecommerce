<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\StoreTransfer;
use App\Services\Accounts\StoreRoles;
use App\Services\Catalog\StoreCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stock envoyé aux autres boutiques de la même adresse (ou reçu d'elles).
 */
class StoreTransferController extends Controller
{
    public function __construct(private readonly StoreCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json([
            'data' => $this->catalog->transfers($store)->map(fn (StoreTransfer $t) => [
                'id' => $t->id,
                'direction' => $t->from_store_id === $store->id ? 'out' : 'in',
                'from' => $t->fromStore?->name,
                'to' => $t->toStore?->name,
                'quantity' => $t->quantity,
                'lines' => $t->lines,
                'note' => $t->note,
                'author' => $t->author?->name,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
            'stores' => app(StoreRoles::class)->otherAddressStores($request->user(), $store, Permission::StockManage)
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ], [], ['store_id' => 'boutique', 'items' => 'articles', 'items.*.quantity' => 'quantité']);

        $transfer = $this->catalog->transfer($request->user(), $this->currentStore(), (int) $data['store_id'],
            collect($data['items'])->mapWithKeys(fn ($i) => [$i['variant_id'] => (int) $i['quantity']])->all(),
            $data['note'] ?? null, isset($data['location_id']) ? (int) $data['location_id'] : null);

        return response()->json([
            'data' => ['id' => $transfer->id],
            'message' => "{$transfer->quantity} article(s) envoyé(s) à {$transfer->toStore->name}.",
        ], 201);
    }
}
