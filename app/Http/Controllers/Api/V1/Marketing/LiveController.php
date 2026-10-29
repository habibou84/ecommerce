<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\LiveItem;
use App\Models\LiveReservation;
use App\Models\LiveSession;
use App\Models\ProductVariant;
use App\Services\Live\LiveSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vente en live : préparation (articles, codes, prix, pièces), écran du live (réservations en direct, mode
 * assistant) et bilan.
 */
class LiveController extends Controller
{
    public function __construct(private readonly LiveSales $live) {}

    public function index(): JsonResponse
    {
        $store = $this->currentStore();
        $sessions = LiveSession::forStore($store->id)->withCount('items')->latest('id')->limit(30)->get();

        return response()->json([
            'data' => $sessions->map(fn (LiveSession $s) => [
                'id' => $s->id, 'title' => $s->title, 'status' => $s->status, 'items_count' => $s->items_count,
                'started_at' => $s->started_at?->toIso8601String(), 'ended_at' => $s->ended_at?->toIso8601String(),
                'minutes' => $s->started_at ? (int) $s->started_at->diffInMinutes($s->ended_at ?? now()) : null,
                'summary' => $s->status === 'draft' ? null : $this->live->summary($s),
            ])->values(),
            'meta' => ['page_url' => $store->url('/live'), 'whatsapp' => LiveSales::whatsapp($store->id)],
        ]);
    }

    /**
     * Articles à présenter : recherche dans le catalogue, avec prix et stock disponible.
     */
    public function variants(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim((string) $request->query('search'));
        $variants = ProductVariant::query()->whereHas('product')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('title', 'ilike', "%{$search}%"))
                ->orWhere('sku', 'ilike', "%{$search}%")))
            ->with(['product:id,title', 'product.images', 'levels'])->orderBy('product_id')->orderBy('position')->limit(30)->get();

        return response()->json(['data' => $variants->map(fn (ProductVariant $v) => [
            'id' => $v->id,
            'title' => $v->fullTitle(),
            'price' => $v->price,
            'available' => (int) $v->levels->sum(fn ($l) => $l->available()),
            'image' => $v->product?->images->first()?->urls()['thumb'] ?? null,
        ])->values()]);
    }

    public function show(int $session): JsonResponse
    {
        return $this->state($this->session($session));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->state($this->live->save($this->currentStore(), null, $this->validated($request), $request->user()), 201);
    }

    public function update(Request $request, int $session): JsonResponse
    {
        return $this->state($this->live->save($this->currentStore(), $this->session($session), $this->validated($request), $request->user()));
    }

    /**
     * Articles ajoutés pendant le live : codes déjà annoncés inchangés.
     */
    public function addItems(Request $request, int $session): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);
        $live = $this->session($session);
        $added = $this->live->addItems($live, $data['items']);

        return response()->json(['data' => $this->live->state($live->fresh()), 'meta' => ['codes' => $added->pluck('code')->unique()->values()]]);
    }

    /**
     * Article montré en ce moment : mis en avant sur la page du live (item_id null : plus aucun).
     */
    public function feature(Request $request, int $session): JsonResponse
    {
        $data = $request->validate(['item_id' => ['nullable', 'integer']]);

        return $this->state($this->live->feature($this->session($session), $data['item_id'] ?? null));
    }

    /**
     * Refaire ce live : nouveau live en préparation avec les mêmes articles.
     */
    public function duplicate(Request $request, int $session): JsonResponse
    {
        return $this->state($this->live->duplicate($this->session($session), $request->user()), 201);
    }

    public function start(int $session): JsonResponse
    {
        return $this->state($this->live->start($this->session($session)));
    }

    public function end(int $session): JsonResponse
    {
        return $this->state($this->live->end($this->session($session)));
    }

    /**
     * Mode assistant : réservation saisie par l'équipe (pseudo ou numéro, code ; quartier si on l'a).
     */
    public function reserve(Request $request, int $session): JsonResponse
    {
        $live = $this->session($session);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'],
            'name' => ['nullable', 'required_without:phone', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'place_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);
        $item = LiveItem::forStore($live->store_id)->where('live_session_id', $live->id)->where('code', strtoupper(trim($data['code'])))->first();
        if ($item === null) {
            return response()->json(['message' => "Aucun article {$data['code']} dans ce live.", 'errors' => ['code' => ["Aucun article {$data['code']} dans ce live."]]], 422);
        }
        $this->live->reserve($item, $data, 'manual');

        return $this->state($live->fresh());
    }

    public function cancel(int $session, int $reservation): JsonResponse
    {
        $live = $this->session($session);
        $this->live->cancel(LiveReservation::forStore($live->store_id)->where('live_session_id', $live->id)->findOrFail($reservation));

        return $this->state($live->fresh());
    }

    private function session(int $id): LiveSession
    {
        return LiveSession::forStore($this->currentStore()->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'hold_minutes' => ['nullable', 'integer', 'between:10,1440'],
            'stream_url' => ['nullable', 'url:https', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ]);
    }

    private function state(LiveSession $session, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->live->state($session)], $status);
    }
}
