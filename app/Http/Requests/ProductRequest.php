<?php

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:20000'],
            'brand' => ['nullable', 'string', 'max:80'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'is_visible_online' => ['sometimes', 'boolean'],
            'track_inventory' => ['sometimes', 'boolean'],
            'allow_backorder' => ['sometimes', 'boolean'],
            'preorder_enabled' => ['sometimes', 'boolean'],
            'preorder_ship_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'preorder_deposit_percent' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            // Acompte de l'article : pourcentage du prix ou montant fixe par pièce
            'deposit_type' => ['sometimes', 'nullable', 'in:percent,fixed'],
            // Un pourcentage ne dépasse pas 100 ; un montant fixe est en francs par pièce
            'deposit_value' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::when($this->input('deposit_type') === 'percent', 'max:100', 'max:100000000'), 'required_with:deposit_type'],
            'requires_shipping' => ['sometimes', 'boolean'],
            'is_fragile' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:160'],

            'options' => ['present', 'array', 'max:3'],
            'options.*.name' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:50'],
            'options.*.values.*' => ['required'],

            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.options' => ['sometimes', 'array', 'max:3'],
            'variants.*.options.*' => ['nullable', 'string', 'max:60'],
            'variants.*.price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'variants.*.compare_at_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'variants.*.cost_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60'],
            'variants.*.weight_g' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.image_id' => ['nullable', 'integer'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.on_hand' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            'collection_ids' => ['sometimes', 'array'],
            'collection_ids.*' => ['integer'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'location_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Un prix barré est l'ancien prix : il ne peut pas être inférieur au prix de vente.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('variants', []) as $index => $variant) {
                $compare = $variant['compare_at_price'] ?? null;
                $price = $variant['price'] ?? null;
                if (is_numeric($compare) && is_numeric($price) && (int) $compare < (int) $price) {
                    $validator->errors()->add("variants.{$index}.compare_at_price", 'Le prix barré doit être supérieur ou égal au prix.');
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'deposit_value' => 'acompte', 'variants.*.compare_at_price' => 'prix barré',
            'title' => 'nom', 'description' => 'description', 'options.*.name' => 'nom de l\'option',
            'options.*.values' => 'valeurs de l\'option', 'variants' => 'variantes', 'variants.*.price' => 'prix',
            'variants.*.sku' => 'SKU', 'variants.*.on_hand' => 'stock',
        ];
    }
}
