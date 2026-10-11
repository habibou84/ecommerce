<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Storefront\CartService;
use App\Services\Storefront\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts, private readonly StorefrontView $view) {}

    public function show(Request $request): View
    {
        $store = $this->currentStore();

        return view('storefront.cart', $this->view->shared($request, $store) + [
            'summary' => $this->carts->summary($store, $this->carts->find($request, $store)),
            'meta' => ['title' => 'Panier · '.$store->name, 'description' => '', 'path' => '/panier', 'noindex' => true],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => $this->carts->summary($store, $this->carts->find($request, $store))]);
    }

    /**
     * Ajout au panier (ou quantité fixée avec replace=1 ; 0 retire l'article).
     */
    public function put(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        $store = $this->currentStore();
        $cart = $this->carts->findOrCreate($request, $store);
        $this->carts->put($store, $cart, $data['variant_id'], $data['quantity'], $request->boolean('replace'));

        return response()->json(['data' => $this->carts->summary($store, $cart)])->withCookie($this->carts->cookie($cart));
    }
}
