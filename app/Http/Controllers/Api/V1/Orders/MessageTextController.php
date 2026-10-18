<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\MessageTexts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Textes des messages au client : modification et aperçu.
 */
class MessageTextController extends Controller
{
    public function __construct(private readonly MessageTexts $texts) {}

    public function index(): JsonResponse
    {
        $store = $this->currentStore();
        $sample = $this->sample();

        return response()->json(['data' => [
            'messages' => collect(MessageTexts::MESSAGES)->map(fn ($m, $key) => [
                'key' => $key,
                'label' => $m['label'],
                'default' => $m['text'],
                'text' => $this->texts->text($store, $key),
                'custom' => filled($store->message_texts[$key] ?? null),
                'preview' => $this->texts->render($sample, $key),
            ])->values(),
            'placeholders' => collect(MessageTexts::PLACEHOLDERS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['texts' => ['required', 'array'], 'texts.*' => ['nullable', 'string', 'max:1500']]);
        $this->texts->save($this->currentStore(), $data['texts']);

        return $this->index();
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', Rule::in(array_keys(MessageTexts::MESSAGES))], 'text' => ['required', 'string', 'max:1500']]);

        return response()->json(['data' => ['preview' => $this->texts->render($this->sample(), $data['key'], $data['text'])]]);
    }

    /**
     * Dernière commande de la boutique, sinon une commande d'exemple (non enregistrée).
     */
    private function sample(): Order
    {
        $store = $this->currentStore();
        $order = Order::with(['items', 'store'])->where('status', '!=', OrderStatus::Cancelled->value)->whereNotNull('customer_phone')->latest()->first();
        if ($order) {
            return $order;
        }

        $order = (new Order)->forceFill([
            'store_id' => $store->id, 'number' => 1001, 'customer_name' => 'Awa Koné', 'delivery_method' => 'delivery', 'shipping_place_label' => 'Angré, Cocody',
            'shipping_address' => 'Cité Arcades', 'subtotal' => 30000, 'shipping_fee' => 1500, 'total' => 31500, 'amount_paid' => 0, 'amount_refunded' => 0,
            'status' => OrderStatus::Confirmed, 'tracking_token' => 'exemple',
        ]);
        $order->setRelation('store', $store);
        $order->setRelation('items', collect([(new OrderItem)->forceFill(['title' => 'Robe wax', 'variant_title' => 'M', 'quantity' => 2, 'total' => 30000])]));

        return $order;
    }
}
