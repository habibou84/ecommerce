<?php

namespace App\Http\Requests;

use App\Enums\OrderSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Création (POST) et modification (PUT, champs facultatifs) d'une commande saisie dans la gestion.
 */
class OrderRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['customer_phone', 'shipping_phone', 'shipping_phone2'] as $field) {
            if (is_string($this->input($field)) && PhoneNumber::isValid($this->input($field))) {
                $this->merge([$field => PhoneNumber::normalize($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'source' => [$creating ? 'required' : 'prohibited', Rule::in(array_map(fn ($s) => $s->value, OrderSource::manual()))],
            'customer_phone' => [$creating ? 'required' : 'prohibited', new PhoneRule],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'delivery_method' => [$required, Rule::in(['delivery', 'pickup'])],
            'shipping_name' => ['nullable', 'string', 'max:120'],
            'shipping_phone' => ['nullable', new PhoneRule],
            'shipping_phone2' => ['nullable', new PhoneRule],
            'shipping_place_id' => ['nullable', 'integer'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'shipping_landmark' => ['nullable', 'string', 'max:255'],
            'shipping_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'shipping_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup_location_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'scheduled_date' => ['nullable', 'date'],
            'time_slot' => ['nullable', 'string', 'max:20'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'discount_total' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'shipping_fee' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'deposit_required' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'confirmed' => ['sometimes', 'boolean'],

            'items' => [$required, 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.title' => ['required_without:items.*.variant_id', 'nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_phone' => 'téléphone du client', 'customer_name' => 'nom du client', 'source' => 'canal',
            'items' => 'articles', 'items.*.quantity' => 'quantité', 'items.*.title' => 'nom de l\'article',
            'items.*.unit_price' => 'prix', 'shipping_fee' => 'frais de livraison', 'discount_total' => 'remise',
            'discount_code' => 'code promo',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function orderData(): array
    {
        $data = $this->validated();
        if (! $this->has('shipping_fee')) {
            unset($data['shipping_fee']);
        }

        return $data;
    }
}
