<?php

namespace App\Services\Storefront;

use App\Exceptions\BusinessRuleException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Promotions\Pricing;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Panier du visiteur, retrouvé par le cookie « panier » (pas de session : la boutique reste légère).
 */
class CartService
{
    public const COOKIE = 'panier';

    public const COOKIE_DAYS = 30;

    public const MAX_LINES = 30;

    public function __construct(private readonly StorefrontCatalog $catalog, private readonly Pricing $pricing) {}

    /**
     * Cookie du panier : un par boutique hébergée, ses pages partageant l'adresse de la boutique hôte.
     */
    public static function cookieName(): string
    {
        $store = app(Tenancy::class)->store();

        return $store?->host_store_id !== null ? self::COOKIE.'_'.$store->path : self::COOKIE;
    }

    public function find(Request $request, Store $store): ?Cart
    {
        $token = $request->cookie(self::cookieName());

        return is_string($token) && $token !== ''
            ? Cart::forStore($store->id)->where('token', $token)->where('status', 'active')->first()
            : null;
    }

    public function findOrCreate(Request $request, Store $store): Cart
    {
        return $this->find($request, $store) ?? Cart::create([
            'store_id' => $store->id,
            'token' => Str::random(40),
            'status' => 'active',
            'utm' => $this->utm($request),
            'last_activity_at' => now(),
        ]);
    }

    public function cookie(Cart $cart): Cookie
    {
        return cookie(self::cookieName(), $cart->token, self::COOKIE_DAYS * 24 * 60, '/', null, null, true, false, 'lax');
    }

    /**
     * Ajoute (ou fixe, $replace) la quantité d'une variante, dans la limite du stock vendable.
     */
    public function put(Store $store, Cart $cart, int $variantId, int $quantity, bool $replace = false): void
    {
        $variant = ProductVariant::forStore($store->id)->where('is_active', true)->with('product')->find($variantId);

        if ($variant === null || ! $this->catalog->products($store)->whereKey($variant->product_id)->exists()) {
            throw new BusinessRuleException('Cet article n\'est plus en vente.', 'variant_id');
        }

        $item = $cart->items()->where('variant_id', $variantId)->first();
        $total = $replace ? $quantity : ($item?->quantity ?? 0) + $quantity;

        if ($total <= 0) {
            $item?->delete();
            $this->touch($cart);

            return;
        }

        if ($item === null && $cart->items()->count() >= self::MAX_LINES) {
            throw new BusinessRuleException('Votre panier est plein.');
        }

        $sellable = $this->catalog->sellable($variant, $this->catalog->availability($store, [$variant->id]));
        if ($sellable !== null && $total > $sellable) {
            throw new BusinessRuleException($sellable > 0 ? "Il n'en reste que {$sellable}." : 'Cet article est épuisé.', 'quantity');
        }

        CartItem::updateOrCreate(['cart_id' => $cart->id, 'variant_id' => $variantId], ['quantity' => min($total, 999)]);
        $this->touch($cart);
    }

    /**
     * Contenu du panier aux prix et stocks du moment (ventes flash, prix par quantité, code promo) ;
     * les articles qui ne sont plus en vente sont retirés.
     *
     * @return array{lines: list<array<string, mixed>>, count: int, subtotal: int, discount: ?array<string, mixed>, total: int, free_shipping: ?array{threshold: int, remaining: int}, removed: list<string>}
     */
    public function summary(Store $store, ?Cart $cart): array
    {
        if ($cart === null) {
            return ['lines' => [], 'count' => 0, 'subtotal' => 0, 'discount' => null, 'total' => 0, 'free_shipping' => $this->freeShipping($store, 0), 'removed' => []];
        }

        $items = $cart->items()->with(['variant.product.images', 'variant.image'])->get();
        $visible = $this->catalog->products($store)->whereIn('id', $items->pluck('variant.product_id')->filter())->pluck('id')->all();
        $availability = $this->catalog->availability($store, $items->pluck('variant_id')->all());
        $kept = [];
        $removed = [];

        foreach ($items as $item) {
            $variant = $item->variant;
            if ($variant === null || ! $variant->is_active || ! in_array($variant->product_id, $visible, true)) {
                $removed[] = $variant?->fullTitle() ?? 'Article';
                $item->delete();

                continue;
            }
            $kept[] = $item;
        }

        $prices = $this->pricing->lines($store->id, array_map(fn ($item) => ['variant' => $item->variant, 'quantity' => $item->quantity], $kept));
        $lines = [];
        foreach ($kept as $index => $item) {
            $variant = $item->variant;
            $sellable = $this->catalog->sellable($variant, $availability);
            $image = $variant->image ?? $variant->product->images->first();
            $lines[] = [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'product_slug' => $variant->product->slug,
                'title' => $variant->product->title,
                'variant_title' => $variant->is_default ? null : $variant->title,
                'unit_price' => $prices[$index]['unit_price'],
                'compare_at' => $prices[$index]['compare_at'],
                'volume' => $prices[$index]['volume'],
                'quantity' => $item->quantity,
                'total' => $prices[$index]['total'],
                'image' => $image?->urls()['thumb'],
                'max' => $sellable,
                'short' => $sellable !== null && $item->quantity > $sellable,
            ];
        }

        $subtotal = array_sum(array_column($lines, 'total'));
        $code = $cart->discount_code && $lines !== []
            ? $this->pricing->code($store->id, $cart->discount_code, array_map(fn ($l) => ['product_id' => $l['product_id'], 'total' => $l['total']], $lines), $cart->phone)
            : null;

        return [
            'lines' => $lines,
            'count' => array_sum(array_column($lines, 'quantity')),
            'subtotal' => $subtotal,
            'discount' => $code ? ['code' => $code['code'], 'amount' => $code['amount'], 'free_shipping' => $code['free_shipping'], 'label' => $code['label'], 'error' => $code['error']] : null,
            'total' => $subtotal - ($code['amount'] ?? 0),
            'free_shipping' => $this->freeShipping($store, $subtotal),
            'removed' => $removed,
        ];
    }

    /**
     * Code promo saisi par le client (vide : retiré). Refusé s'il ne s'applique pas au panier.
     */
    public function applyCode(Store $store, Cart $cart, ?string $code): void
    {
        $code = Pricing::normalize($code);
        if ($code !== null) {
            $summary = $this->summary($store, $cart->setAttribute('discount_code', $code));
            if ($summary['discount']['error'] ?? null) {
                throw new BusinessRuleException($summary['discount']['error'], 'code');
            }
        }

        $cart->forceFill(['discount_code' => $code, 'last_activity_at' => now()])->save();
    }

    /**
     * Livraison offerte dès un montant (réglage de la boutique) : ce qu'il reste à ajouter.
     *
     * @return array{threshold: int, remaining: int}|null
     */
    private function freeShipping(Store $store, int $subtotal): ?array
    {
        return $store->free_shipping_above ? ['threshold' => $store->free_shipping_above, 'remaining' => max(0, $store->free_shipping_above - $subtotal)] : null;
    }

    public function saveContact(Cart $cart, ?string $name, ?string $phone): void
    {
        $cart->forceFill(['name' => $name ?: $cart->name, 'phone' => $phone ?: $cart->phone, 'last_activity_at' => now()])->save();
    }

    private function touch(Cart $cart): void
    {
        $cart->forceFill(['last_activity_at' => now()])->save();
    }

    public const ORIGIN_COOKIE = 'origine';

    /**
     * Origine de la visite (publicités Facebook / TikTok, liens partagés), notée à l'arrivée sur la boutique.
     *
     * @return array<string, string>|null
     */
    public function utm(Request $request): ?array
    {
        $fromQuery = $this->utmFromQuery($request);
        if ($fromQuery !== []) {
            return $fromQuery;
        }

        $cookie = json_decode((string) $request->cookie(self::ORIGIN_COOKIE), true);

        return is_array($cookie) && $cookie !== [] ? array_map(fn ($v) => mb_substr((string) $v, 0, 100), $cookie) : null;
    }

    /**
     * @return array<string, string>
     */
    public function utmFromQuery(Request $request): array
    {
        return collect($request->only(['utm_source', 'utm_medium', 'utm_campaign', 'fbclid', 'ttclid']))
            ->filter(fn ($v) => is_string($v) && $v !== '')->map(fn ($v) => mb_substr($v, 0, 100))->all();
    }
}
