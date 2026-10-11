<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Discount;
use App\Models\DiscountUsage;
use App\Models\Product;
use App\Services\Promotions\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Codes promo, ventes flash (remises automatiques) et livraison offerte dès un montant.
 */
class PromotionController extends Controller
{
    public function index(): JsonResponse
    {
        $discounts = Discount::orderByRaw('code is null desc')->latest('id')->get();
        // Ventes et remises générées par chaque promotion (commandes non annulées)
        $stats = DiscountUsage::query()->join('orders', 'orders.id', '=', 'discount_usages.order_id')
            ->where('orders.status', '!=', 'cancelled')
            ->groupBy('discount_usages.discount_id')
            ->select('discount_usages.discount_id', DB::raw('COUNT(*) AS orders'), DB::raw('SUM(discount_usages.amount) AS discounted'), DB::raw('SUM(orders.total) AS revenue'))
            ->get()->keyBy('discount_id');

        $targets = $this->targetNames($discounts);

        return response()->json([
            'data' => $discounts->map(fn (Discount $d) => $this->present($d, $stats->get($d->id), $targets)),
            'free_shipping_above' => $this->currentStore()->free_shipping_above,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $discount = Discount::create($this->validated($request) + ['store_id' => $this->currentStore()->id]);

        return response()->json(['data' => $this->present($discount, null, $this->targetNames(collect([$discount])))], 201);
    }

    public function update(Request $request, int $discount): JsonResponse
    {
        $model = Discount::findOrFail($discount);
        $model->update($this->validated($request, $model));

        return response()->json(['data' => $this->present($model, null, $this->targetNames(collect([$model])))]);
    }

    /**
     * Une promotion déjà utilisée est désactivée (son historique reste dans les statistiques).
     */
    public function destroy(int $discount): JsonResponse
    {
        $model = Discount::findOrFail($discount);
        if ($model->usages()->exists()) {
            $model->update(['is_active' => false]);

            return response()->json(['message' => 'Promotion déjà utilisée : elle est désactivée et reste dans vos statistiques.']);
        }
        $model->delete();

        return response()->json(['message' => 'Promotion supprimée.']);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['free_shipping_above' => ['nullable', 'integer', 'min:0', 'max:100000000']], [], ['free_shipping_above' => 'montant']);
        $this->currentStore()->forceFill(['free_shipping_above' => $data['free_shipping_above'] ?: null])->save();

        return response()->json(['free_shipping_above' => $this->currentStore()->free_shipping_above]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Discount $model = null): array
    {
        $request->merge(['code' => Pricing::normalize($request->input('code'))]);
        $automatic = $request->input('code') === null;
        $storeId = $this->currentStore()->id;

        $data = $request->validate([
            'code' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('discounts', 'code')->where('store_id', $storeId)->ignore($model?->id)],
            'title' => [$automatic ? 'required' : 'nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in($automatic ? ['percentage', 'fixed_amount'] : array_keys(Discount::TYPES))],
            'value' => [Rule::requiredIf(fn () => $request->input('type') !== 'free_shipping'), 'nullable', 'integer', 'min:1',
                $request->input('type') === 'percentage' ? 'max:100' : 'max:100000000'],
            'applies_to' => ['required', Rule::in(['all', 'collections', 'products'])],
            'target_ids' => ['required_unless:applies_to,all', 'nullable', 'array', 'max:200'],
            'target_ids.*' => ['integer'],
            'min_subtotal' => ['nullable', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => array_filter([$automatic ? 'required' : 'nullable', 'date', $request->filled('starts_at') ? 'after:starts_at' : null, $model ? null : 'after:now']),
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'code.regex' => 'Le code ne contient que des lettres, des chiffres, - et _.',
            'code.unique' => 'Ce code existe déjà.',
            'ends_at.required' => 'Une vente flash a une date de fin (compte à rebours).',
            'ends_at.after' => 'La fin doit être après le début et dans le futur.',
            'target_ids.required_unless' => 'Choisissez les produits ou collections visés.',
        ], ['title' => 'nom', 'value' => 'valeur', 'ends_at' => 'fin', 'starts_at' => 'début']);

        $model = $data['applies_to'] === 'collections' ? Collection::class : Product::class;
        $data['target_ids'] = $data['applies_to'] === 'all' ? null : $model::whereIn('id', $data['target_ids'] ?? [])->pluck('id')->all();
        $data['value'] = $data['type'] === 'free_shipping' ? 0 : (int) $data['value'];

        return $data;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Discount>  $discounts
     * @return array{products: array<int, string>, collections: array<int, string>}
     */
    private function targetNames($discounts): array
    {
        $ids = fn (string $type) => $discounts->where('applies_to', $type)->flatMap(fn ($d) => $d->target_ids ?? [])->unique()->all();

        return [
            'products' => Product::whereIn('id', $ids('products'))->pluck('title', 'id')->all(),
            'collections' => Collection::whereIn('id', $ids('collections'))->pluck('title', 'id')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Discount $d, ?object $stats, array $targets): array
    {
        $names = $targets[$d->applies_to] ?? [];

        return [
            'id' => $d->id,
            'code' => $d->code,
            'title' => $d->title,
            'kind' => $d->isAutomatic() ? 'flash' : 'code',
            'type' => $d->type,
            'value' => $d->value,
            'summary' => $d->summary(),
            'applies_to' => $d->applies_to,
            'target_ids' => $d->target_ids ?? [],
            'targets' => collect($d->target_ids ?? [])->map(fn ($id) => $names[$id] ?? null)->filter()->values(),
            'min_subtotal' => $d->min_subtotal,
            'usage_limit' => $d->usage_limit,
            'per_customer_limit' => $d->per_customer_limit,
            'used_count' => $d->used_count,
            'starts_at' => $d->starts_at?->toIso8601String(),
            'ends_at' => $d->ends_at?->toIso8601String(),
            'is_active' => $d->is_active,
            'status' => match (true) {
                ! $d->is_active => 'disabled',
                $d->starts_at?->isFuture() => 'scheduled',
                $d->ends_at?->isPast() => 'ended',
                default => 'running',
            },
            'orders' => (int) ($stats->orders ?? 0),
            'discounted' => (int) ($stats->discounted ?? 0),
            'revenue' => (int) ($stats->revenue ?? 0),
        ];
    }
}
