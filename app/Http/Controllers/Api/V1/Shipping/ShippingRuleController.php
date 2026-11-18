<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Place;
use App\Models\ShippingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Règles d'affectation des commandes confirmées (livreur de la boutique, transporteur, le moins cher, le plus rapide)
 * et mode : désactivé, suggestion sur la fiche commande, ou expédition créée automatiquement.
 */
class ShippingRuleController extends Controller
{
    /** Délais proposés avant de passer la mission au livreur suivant (minutes) */
    private const TIMEOUTS = [10, 15, 30, 60];

    private const SUMMARY_DAYS = 7;

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ShippingRule::with('connection:id,name')->orderBy('priority')->orderBy('id')->get()->map(fn (ShippingRule $r) => $this->present($r)),
            'assignment_mode' => $this->currentStore()->assignment_mode,
            'courier_dispatch' => $this->currentStore()->courier_dispatch,
            'mission_timeout_minutes' => $this->currentStore()->mission_timeout_minutes,
            'summary' => $this->summary(),
            'actions' => ShippingRule::ACTIONS,
            'carriers' => CarrierConnection::where('status', 'active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = ShippingRule::create($this->validated($request) + [
            'store_id' => $this->currentStore()->id,
            'priority' => (int) ShippingRule::max('priority') + 1,
        ]);

        return response()->json(['data' => $this->present($rule->load('connection:id,name'))], 201);
    }

    public function update(Request $request, int $rule): JsonResponse
    {
        $model = ShippingRule::findOrFail($rule);
        $model->update($this->validated($request));

        return response()->json(['data' => $this->present($model->load('connection:id,name'))]);
    }

    public function destroy(int $rule): JsonResponse
    {
        ShippingRule::findOrFail($rule)->delete();

        return response()->json(['message' => 'Règle supprimée.']);
    }

    /**
     * Ordre des règles (la première qui correspond s'applique) et mode d'affectation.
     */
    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignment_mode' => ['sometimes', Rule::in(['off', 'suggest', 'auto'])],
            'courier_dispatch' => ['sometimes', Rule::in(['nearest', 'round_robin'])],
            'mission_timeout_minutes' => ['sometimes', 'nullable', 'integer', Rule::in(self::TIMEOUTS)],
            'order' => ['sometimes', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            $store = $this->currentStore();
            $store->forceFill(array_intersect_key($data, array_flip(['assignment_mode', 'courier_dispatch', 'mission_timeout_minutes'])))->save();
            foreach (array_values($data['order'] ?? []) as $i => $id) {
                ShippingRule::whereKey($id)->update(['priority' => $i + 1]);
            }
        });

        return $this->index();
    }

    /**
     * Bilan de l'envoi automatique sur 7 jours : commandes envoyées toutes seules, et commandes bloquées (le dernier
     * essai a échoué et personne ne livre encore), à reprendre à la main.
     *
     * @return array{days: int, sent: int, failed: int, blocked: list<array<string, mixed>>}
     */
    private function summary(): array
    {
        $since = now()->subDays(self::SUMMARY_DAYS);
        $counts = OrderEvent::whereIn('type', ['auto_assigned', 'auto_assignment_failed'])->where('created_at', '>=', $since)
            ->selectRaw('type, count(distinct order_id) as n')->groupBy('type')->pluck('n', 'type');

        $active = array_values(array_filter(array_map(fn (ShipmentStatus $s) => $s->isFinal() ? null : $s->value, ShipmentStatus::cases())));
        $blocked = Order::with(['events' => fn ($q) => $q->whereIn('type', ['auto_assigned', 'auto_assignment_failed', 'shipment_created'])->latest('id')])
            ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Processing->value])
            ->whereDoesntHave('shipments', fn ($q) => $q->whereIn('status', $active))
            ->whereHas('events', fn ($q) => $q->where('type', 'auto_assignment_failed')->where('created_at', '>=', $since))
            ->latest('id')->limit(50)->get()
            ->filter(fn (Order $o) => $o->events->first()?->type === 'auto_assignment_failed')
            ->take(10)
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'reference' => $o->reference(),
                'customer' => $o->shipping_name ?? $o->customer?->name,
                'place' => $o->shipping_place_label,
                'reason' => $o->events->first()->data['error'] ?? null,
                'at' => $o->events->first()->created_at->toIso8601String(),
            ])->values()->all();

        return ['days' => self::SUMMARY_DAYS, 'sent' => (int) ($counts['auto_assigned'] ?? 0), 'failed' => (int) ($counts['auto_assignment_failed'] ?? 0), 'blocked' => $blocked];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'action' => ['required', Rule::in(array_keys(ShippingRule::ACTIONS))],
            'carrier_connection_id' => ['required_if:action,carrier', 'nullable', 'integer', Rule::exists('carrier_connections', 'id')->where('store_id', $this->currentStore()->id)],
            'conditions' => ['nullable', 'array'],
            'conditions.place_ids' => ['nullable', 'array', 'max:100'],
            'conditions.place_ids.*' => ['integer'],
            'conditions.is_fragile' => ['nullable', 'boolean'],
            'conditions.min_amount' => ['nullable', 'integer', 'min:0'],
            'conditions.max_amount' => ['nullable', 'integer', 'min:0'],
            'conditions.max_weight_g' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['carrier_connection_id.required_if' => 'Choisissez l\'entreprise de livraison.'], ['name' => 'nom', 'action' => 'action']);

        $conditions = $data['conditions'] ?? [];
        if (! empty($conditions['place_ids'])) {
            $conditions['place_ids'] = Place::visibleTo($this->currentStore()->id)->whereIn('id', $conditions['place_ids'])->pluck('id')->all();
        }
        $data['conditions'] = array_filter($conditions, fn ($v) => $v !== null && $v !== []) ?: null;
        $data['carrier_connection_id'] = $data['action'] === 'carrier' ? $data['carrier_connection_id'] : null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShippingRule $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'priority' => $r->priority,
            'action' => $r->action,
            'action_label' => ShippingRule::ACTIONS[$r->action] ?? $r->action,
            'carrier_connection_id' => $r->carrier_connection_id,
            'carrier' => $r->connection?->name,
            'conditions' => (object) ($r->conditions ?? []),
            // Lieux du cas, nommés (« Angré, Cocody »)
            'places' => Place::with('parent')->whereIn('id', $r->conditions['place_ids'] ?? [])->get()
                ->map(fn (Place $p) => ['id' => $p->id, 'label' => $p->label()])->values(),
            'is_active' => $r->is_active,
        ];
    }
}
