<?php

namespace App\Http\Controllers\Api\V1\Stock;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Stock\PurchaseOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

/**
 * Fournisseurs et bons de commande.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrders $orders) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(array_keys(PurchaseOrder::STATUSES))]]);
        $page = PurchaseOrder::with(['supplier', 'location', 'items'])->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))->latest()->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (PurchaseOrder $o) => $this->orders->present($o, false)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function suggestions(): JsonResponse
    {
        return response()->json(['data' => $this->orders->suggestions($this->currentStore())]);
    }

    public function show(int $order): JsonResponse
    {
        return response()->json(['data' => $this->orders->present(PurchaseOrder::findOrFail($order))]);
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => $this->orders->present($this->orders->save($store, null, $this->validated($request), $request->user()))], 201);
    }

    public function update(Request $request, int $order): JsonResponse
    {
        $store = $this->currentStore();
        $model = PurchaseOrder::findOrFail($order);

        return response()->json(['data' => $this->orders->present($this->orders->save($store, $model, $this->validated($request), $request->user()))]);
    }

    public function send(int $order): JsonResponse
    {
        $result = $this->orders->send($this->currentStore(), PurchaseOrder::findOrFail($order));

        return response()->json(['data' => $this->orders->present($result['order']) + ['whatsapp_url' => $result['whatsapp_url'], 'text' => $result['text']]]);
    }

    public function receive(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [], ['items' => 'articles']);

        return response()->json(['data' => $this->orders->present($this->orders->receive(PurchaseOrder::findOrFail($order), $data['items'], $request->user()))]);
    }

    public function pay(Request $request, int $order): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
        ], [], ['amount' => 'montant', 'method' => 'moyen']);

        return response()->json(['data' => $this->orders->present($this->orders->pay($this->currentStore(), PurchaseOrder::findOrFail($order), $data['amount'], $data['method'], $request->user()))]);
    }

    public function cancel(int $order): JsonResponse
    {
        return response()->json(['data' => $this->orders->present($this->orders->cancel(PurchaseOrder::findOrFail($order)))]);
    }

    public function suppliers(): JsonResponse
    {
        return response()->json(['data' => $this->orders->suppliers($this->currentStore())->map(fn (Supplier $s) => $this->supplier($s))]);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $supplier = Supplier::create($this->supplierData($request) + ['store_id' => $this->currentStore()->id]);

        return response()->json(['data' => $this->supplier($supplier)], 201);
    }

    public function updateSupplier(Request $request, int $supplier): JsonResponse
    {
        $model = Supplier::findOrFail($supplier);
        $model->update($this->supplierData($request));

        return response()->json(['data' => $this->supplier($model)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function supplier(Supplier $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone, 'phone_display' => $s->phone ? PhoneNumber::display($s->phone) : null, 'note' => $s->note, 'orders_count' => $s->purchase_orders_count ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierData(Request $request): array
    {
        if (is_string($request->input('phone')) && PhoneNumber::isValid($request->input('phone'))) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', new PhoneRule],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'nom', 'phone' => 'téléphone']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        if (is_string($request->input('supplier_phone')) && PhoneNumber::isValid($request->input('supplier_phone'))) {
            $request->merge(['supplier_phone' => PhoneNumber::normalize($request->input('supplier_phone'))]);
        }

        return $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['nullable', 'string', 'max:120'],
            'supplier_phone' => ['nullable', new PhoneRule],
            'location_id' => ['required', 'integer'],
            'expected_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.variant_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ], [], ['location_id' => 'emplacement', 'items' => 'articles', 'supplier_phone' => 'téléphone du fournisseur']);
    }
}
