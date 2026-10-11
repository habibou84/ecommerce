<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Enums\CountStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\InventoryCount;
use App\Models\StockLocation;
use App\Services\Stock\StockKeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Inventaires : comptage d'un emplacement, puis validation (les quantités comptées remplacent le stock).
 */
class CountController extends Controller
{
    public function __construct(private readonly StockKeeper $keeper) {}

    public function index(): JsonResponse
    {
        $counts = InventoryCount::with('location:id,name')->withCount(['items', 'items as counted_count' => fn ($q) => $q->whereNotNull('counted')])
            ->latest('id')->limit(50)->get();

        return response()->json(['data' => $counts->map(fn (InventoryCount $c) => $this->present($c) + [
            'items_count' => $c->items_count,
            'counted_count' => $c->counted_count,
        ])]);
    }

    public function show(int $count): JsonResponse
    {
        $model = InventoryCount::with(['location:id,name', 'items.variant.product:id,title'])->findOrFail($count);

        return response()->json(['data' => $this->present($model) + [
            'items' => $model->items->sortBy(fn ($i) => $i->variant?->fullTitle())->values()->map(fn ($i) => [
                'variant_id' => $i->variant_id,
                'variant' => $i->variant?->fullTitle(),
                'sku' => $i->variant?->sku,
                'expected' => $i->expected,
                'counted' => $i->counted,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:255']]);
        $count = $this->keeper->startCount($request->user(), StockLocation::where('is_active', true)->findOrFail($data['location_id']), $data['note'] ?? null);

        return response()->json(['data' => ['id' => $count->id]], 201);
    }

    /**
     * Enregistre des quantités comptées (au fur et à mesure du comptage ; null = pas encore compté).
     */
    public function saveItems(Request $request, int $count): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:2000'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.counted' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $model = InventoryCount::findOrFail($count);
        if ($model->status !== CountStatus::InProgress) {
            throw new BusinessRuleException('Cet inventaire est clos.');
        }

        DB::transaction(function () use ($model, $data) {
            foreach ($data['items'] as $item) {
                $model->items()->where('variant_id', $item['variant_id'])->update(['counted' => $item['counted']]);
            }
        });

        return response()->json(['message' => 'Comptage enregistré.']);
    }

    public function complete(Request $request, int $count): JsonResponse
    {
        $this->keeper->completeCount($request->user(), InventoryCount::findOrFail($count));

        return response()->json(['message' => 'Inventaire validé : le stock est à jour.']);
    }

    public function cancel(int $count): JsonResponse
    {
        $model = InventoryCount::findOrFail($count);
        if ($model->status !== CountStatus::InProgress) {
            throw new BusinessRuleException('Cet inventaire est clos.');
        }
        $model->update(['status' => CountStatus::Cancelled]);

        return response()->json(['message' => 'Inventaire annulé.']);
    }

    private function present(InventoryCount $count): array
    {
        return [
            'id' => $count->id,
            'location_id' => $count->location_id,
            'location' => $count->location?->name,
            'status' => $count->status->value,
            'status_label' => $count->status->label(),
            'note' => $count->note,
            'created_at' => $count->created_at->toIso8601String(),
            'completed_at' => $count->completed_at?->toIso8601String(),
        ];
    }
}
