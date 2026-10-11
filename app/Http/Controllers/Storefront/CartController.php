<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Services\Storefront\CartService;
use App\Services\Storefront\StorefrontView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    /**
     * Lien de relance d'un panier abandonné (WhatsApp) : le panier est retrouvé sur ce téléphone.
     */
    public function resume(string $token): RedirectResponse
    {
        $cart = Cart::forStore($this->currentStore()->id)->where('token', $token)->where('status', 'active')->first();

        return $cart ? redirect('/panier')->withCookie($this->carts->cookie($cart)) : redirect('/');
    }

    public function summary(Request $request): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => $this->carts->summary($store, $this->carts->find($request, $store))]);
    }

    /**
     * Code promo saisi par le client (vide : retiré).
     */
    public function code(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:40']]);
        $store = $this->currentStore();
        $cart = $this->carts->find($request, $store) ?? abort(422, 'Votre panier est vide.');
        $this->carts->applyCode($store, $cart, $data['code'] ?? null);

        return response()->json(['data' => $this->carts->summary($store, $cart)]);
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
