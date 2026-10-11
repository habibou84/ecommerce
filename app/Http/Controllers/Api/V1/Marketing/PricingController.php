<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VolumePrice;
use App\Services\Promotions\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prix par quantité d'un produit ; prix du moment pour la saisie d'une commande (ventes flash, quantité, code promo).
 */
class PricingController extends Controller
{
    public function volumePrices(int $product): JsonResponse
    {
        Product::findOrFail($product);

        return response()->json(['data' => VolumePrice::where('product_id', $product)->orderBy('min_quantity')->get(['min_quantity', 'price'])]);
    }

    public function updateVolumePrices(Request $request, int $product): JsonResponse
    {
        $model = Product::findOrFail($product);
        $data = $request->validate([
            'tiers' => ['present', 'array', 'max:10'],
            'tiers.*.min_quantity' => ['required', 'integer', 'min:2', 'max:1000', 'distinct'],
            'tiers.*.price' => ['required', 'integer', 'min:1', 'max:100000000'],
        ], ['tiers.*.min_quantity.min' => 'Un palier commence à 2 articles.', 'tiers.*.min_quantity.distinct' => 'Deux paliers ont la même quantité.'], ['tiers.*.price' => 'prix du lot']);

        foreach ($data['tiers'] as $i => $tier) {
            if ($model->price_max !== null && $model->price_max <= $tier['price'] / $tier['min_quantity']) {
                throw ValidationException::withMessages(["tiers.{$i}.price" => 'Le prix du lot doit faire baisser le prix unitaire.']);
            }
        }

        DB::transaction(function () use ($model, $data) {
            VolumePrice::where('product_id', $model->id)->delete();
            foreach ($data['tiers'] as $tier) {
                VolumePrice::create(['store_id' => $model->store_id, 'product_id' => $model->id] + $tier);
            }
        });

        return $this->volumePrices($model->id);
    }

    /**
     * Prix proposés pendant la saisie d'une commande (un prix modifié à la main reste celui du vendeur).
     */
    public function quote(Request $request, Pricing $pricing): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array', 'max:50'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'discount_code' => ['nullable', 'string', 'max:40'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'order_id' => ['nullable', 'integer'],
        ]);
        $storeId = $this->currentStore()->id;
        $variants = ProductVariant::with('product')->whereIn('id', collect($data['items'])->pluck('variant_id')->filter())->get()->keyBy('id');

        $catalog = [];
        foreach ($data['items'] as $i => $item) {
            if (isset($item['variant_id']) && $variants->has($item['variant_id'])) {
                $catalog[$i] = ['variant' => $variants->get($item['variant_id']), 'quantity' => (int) $item['quantity']];
            }
        }
        $priced = $catalog === [] ? [] : array_combine(array_keys($catalog), $pricing->lines($storeId, array_values($catalog)));

        $lines = [];
        foreach ($data['items'] as $i => $item) {
            $lines[$i] = isset($priced[$i])
                ? ['unit_price' => $priced[$i]['unit_price'], 'total' => $priced[$i]['total'], 'compare_at' => $priced[$i]['compare_at'], 'volume' => $priced[$i]['volume'], 'flash' => $priced[$i]['discount_id'] !== null]
                : ['unit_price' => (int) ($item['unit_price'] ?? 0), 'total' => (int) ($item['unit_price'] ?? 0) * (int) $item['quantity'], 'compare_at' => null, 'volume' => false, 'flash' => false];
        }

        // Code promo calculé sur les prix envoyés par le formulaire (éventuellement modifiés à la main)
        $forCode = array_map(fn ($i, $item) => [
            'product_id' => isset($catalog[$i]) ? $catalog[$i]['variant']->product_id : null,
            'total' => isset($item['unit_price']) && $item['unit_price'] !== null && (int) $item['unit_price'] !== $lines[$i]['unit_price'] ? (int) $item['unit_price'] * (int) $item['quantity'] : $lines[$i]['total'],
        ], array_keys($data['items']), $data['items']);
        $code = $pricing->code($storeId, $data['discount_code'] ?? null, $forCode, $data['customer_phone'] ?? null, $data['order_id'] ?? null);

        return response()->json(['data' => [
            'lines' => array_values($lines),
            'discount' => $code['code'] ? ['code' => $code['code'], 'amount' => $code['amount'], 'free_shipping' => $code['free_shipping'], 'label' => $code['label'], 'error' => $code['error']] : null,
        ]]);
    }
}
