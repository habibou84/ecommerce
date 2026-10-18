<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\Orders\ReturnDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Retours et échanges d'articles après livraison.
 */
class ReturnController extends Controller
{
    public function __construct(private readonly ReturnDesk $returns) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(OrderReturn::STATUSES))]]);
        $page = OrderReturn::with('order')->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->latest()->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (OrderReturn $r) => $this->returns->present($r)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => ['pending' => OrderReturn::where('status', 'pending')->count()],
        ]);
    }

    public function forOrder(int $order): JsonResponse
    {
        $model = Order::findOrFail($order);

        return response()->json(['data' => OrderReturn::where('order_id', $model->id)->latest()->get()->map(fn (OrderReturn $r) => $this->returns->present($r))]);
    }

    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.replacement_variant_id' => ['nullable', 'integer'],
            'items.*.replacement_quantity' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
            'restock' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'exchange_shipping_fee' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [], ['items' => 'articles', 'reason' => 'motif', 'exchange_shipping_fee' => 'frais de livraison de l\'échange']);
        $model = Order::with('store')->findOrFail($order);

        return response()->json(['data' => $this->returns->present($this->returns->create($model, $data, $request->user()))], 201);
    }

    public function receive(Request $request, int $return): JsonResponse
    {
        $data = $request->validate(['refund' => ['sometimes', 'boolean']]);
        $model = OrderReturn::findOrFail($return);

        // Le remboursement suit le droit « paiements » (comme le bouton Rembourser de la commande)
        $refund = ($data['refund'] ?? true) && $request->user()->can(Permission::PaymentsManage->value);

        return response()->json(['data' => $this->returns->present($this->returns->receive($model, $request->user(), $refund))]);
    }

    public function cancel(Request $request, int $return): JsonResponse
    {
        return response()->json(['data' => $this->returns->present($this->returns->cancel(OrderReturn::findOrFail($return), $request->user()))]);
    }
}
