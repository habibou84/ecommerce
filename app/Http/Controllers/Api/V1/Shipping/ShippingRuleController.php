<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Models\CarrierConnection;
use App\Models\DeliveryZone;
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
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ShippingRule::with('connection:id,name')->orderBy('priority')->orderBy('id')->get()->map(fn (ShippingRule $r) => $this->present($r)),
            'assignment_mode' => $this->currentStore()->assignment_mode,
            'actions' => ShippingRule::ACTIONS,
            'zones' => DeliveryZone::orderBy('sort')->get(['id', 'name']),
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
            'order' => ['sometimes', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($data) {
            if (isset($data['assignment_mode'])) {
                $this->currentStore()->forceFill(['assignment_mode' => $data['assignment_mode']])->save();
            }
            foreach (array_values($data['order'] ?? []) as $i => $id) {
                ShippingRule::whereKey($id)->update(['priority' => $i + 1]);
            }
        });

        return $this->index();
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
            'conditions.delivery_zone_ids' => ['nullable', 'array'],
            'conditions.delivery_zone_ids.*' => ['integer'],
            'conditions.is_fragile' => ['nullable', 'boolean'],
            'conditions.min_amount' => ['nullable', 'integer', 'min:0'],
            'conditions.max_amount' => ['nullable', 'integer', 'min:0'],
            'conditions.max_weight_g' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ], ['carrier_connection_id.required_if' => 'Choisissez le transporteur.'], ['name' => 'nom', 'action' => 'action']);

        $conditions = $data['conditions'] ?? [];
        if (! empty($conditions['delivery_zone_ids'])) {
            $conditions['delivery_zone_ids'] = DeliveryZone::whereIn('id', $conditions['delivery_zone_ids'])->pluck('id')->all();
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
            'is_active' => $r->is_active,
        ];
    }
}
