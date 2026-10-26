<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AccessoryOrder;
use App\Services\Materiel\MaterielShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Matériel de la plateforme, côté gestion : catalogue, commande, paiement Wave annoncé, suivi, annulation.
 */
class MaterielController extends Controller
{
    public function __construct(private readonly MaterielShop $shop) {}

    public function index(): JsonResponse
    {
        $store = $this->currentStore();
        $open = $this->shop->open();

        return response()->json(['data' => [
            'open' => $open,
            'categories' => Accessory::CATEGORIES,
            'products' => $open ? Accessory::where('is_active', true)->orderBy('category')->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Accessory $a) => $this->shop->presentAccessory($a))->values() : [],
            'methods' => $open ? $this->shop->methods() : [],
            'deliveries' => $open ? collect($this->shop->settings()['deliveries'])->map(fn ($d) => collect($d)->only(['code', 'label', 'fee', 'note', 'address']))->values() : [],
            'orders' => AccessoryOrder::where('store_id', $store->id)->latest('id')->limit(20)->get()->map(fn (AccessoryOrder $o) => $this->shop->present($o))->values(),
        ]]);
    }

    /**
     * Suggestion discrète au bon endroit (impression d'étiquettes, page du live, caisse) : un produit de la catégorie.
     */
    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['category' => ['required', 'in:'.implode(',', array_keys(Accessory::CATEGORIES))]]);
        $product = $this->shop->open() ? Accessory::where('is_active', true)->where('category', $request->query('category'))
            ->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0))->orderBy('sort')->orderBy('id')->first() : null;

        return response()->json(['data' => $product ? $this->shop->presentAccessory($product) : null]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:500'],
            'delivery' => ['required', 'string', 'max:140'],
            'payment' => ['required', 'in:wave,cod'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payer_phone' => ['nullable', 'string', 'max:30'],
        ], [], ['items' => 'articles', 'delivery' => 'livraison', 'payment' => 'paiement', 'contact_name' => 'nom', 'contact_phone' => 'téléphone', 'address' => 'adresse', 'payer_phone' => 'numéro Wave']);
        $order = $this->shop->order($this->currentStore(), $request->user(), $data);

        return response()->json(['data' => $this->shop->present($order)], 201);
    }

    public function claim(int $order): JsonResponse
    {
        return response()->json(['data' => $this->shop->present($this->shop->claim($this->find($order)))]);
    }

    public function cancel(int $order): JsonResponse
    {
        return response()->json(['data' => $this->shop->present($this->shop->cancel($this->find($order)))]);
    }

    private function find(int $order): AccessoryOrder
    {
        return AccessoryOrder::where('store_id', $this->currentStore()->id)->findOrFail($order);
    }
}
