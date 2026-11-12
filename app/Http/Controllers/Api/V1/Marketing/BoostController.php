<?php

namespace App\Http\Controllers\Api\V1\Marketing;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Marketing\BoostTextWriter;
use App\Services\Marketing\CatalogFeed;
use App\Services\Marketing\MetaAds;
use App\Services\Platform\Features;
use App\Services\Social\MetaSocial;
use App\Services\Storefront\StorefrontCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Jibiat\Socle\Money;

/**
 * Menu Boost : tout ce qui touche à la pub Facebook et Instagram au même endroit. La connexion (compte, catalogue,
 * pixel, achats envoyés par le serveur), la vérification du pixel (événements reçus par Meta), les meilleures
 * ventes à booster avec un lien suivi (utm_campaign « boost-{produit} ») et un texte prêt, et les commandes venues
 * de ces boosts. La pub elle-même se crée chez Meta et se paie à Meta par la carte du marchand.
 */
class BoostController extends Controller
{
    /** Événements du pixel montrés à la boutique, dans l'ordre du parcours d'achat */
    private const EVENTS = ['PageView' => 'Visites', 'ViewContent' => 'Vues d\'un produit', 'AddToCart' => 'Ajouts au panier', 'InitiateCheckout' => 'Commandes commencées', 'Purchase' => 'Commandes', 'Contact' => 'Contacts WhatsApp'];

    public function show(MetaAds $ads, CatalogFeed $feed, Features $features, StorefrontCatalog $catalog): JsonResponse
    {
        $store = $this->currentStore();
        $account = $ads->account($store);
        $stats = $ads->pixelStats($store);
        $since = now()->subDays(30);

        // Meilleures ventes des 30 derniers jours : les produits à booster d'abord
        $sold = OrderItem::query()->whereNotNull('product_id')
            ->whereIn('order_id', Order::query()->select('id')->where('created_at', '>=', $since)->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value]))
            ->selectRaw('product_id, sum(quantity) as sold')->groupBy('product_id')->orderByDesc('sold')->limit(12)->pluck('sold', 'product_id');
        $products = Product::with('images', 'variants')->whereIn('id', $sold->keys())->where('status', 'active')->get()
            ->sortByDesc(fn (Product $p) => $sold[$p->id] ?? 0)->take(6);
        if ($products->count() < 6) {
            $products = $products->concat(Product::with('images', 'variants')->where('status', 'active')->whereNotIn('id', $products->pluck('id'))
                ->latest('id')->limit(6 - $products->count())->get());
        }

        // Stock vendable et marge (prix moins prix d'achat) : le produit à booster se vend, a du stock et une marge
        $availability = $catalog->availability($store, $products->flatMap->variants->pluck('id')->all());
        $info = $products->mapWithKeys(function (Product $p) use ($catalog, $availability) {
            $stock = 0;
            foreach ($p->variants->where('is_active', true) as $v) {
                $v->setRelation('product', $p);
                $sellable = $catalog->sellable($v, $availability);
                $stock = $stock === null || $sellable === null ? null : $stock + $sellable;
            }
            $cost = $p->variants->filter(fn ($v) => (int) $v->cost_price > 0)->sortBy('price')->first();

            return [$p->id => ['stock' => $stock, 'margin' => $cost ? (int) $cost->price - (int) $cost->cost_price : null]];
        });
        $suggested = $products->filter(fn (Product $p) => ($info[$p->id]['stock'] ?? 0) !== 0 && ($sold[$p->id] ?? 0) > 0)
            ->sortByDesc(fn (Product $p) => ($sold[$p->id] ?? 0) * max(1, $info[$p->id]['margin'] ?? 1))->first()?->id;

        // Commandes venues d'un lien « Booster » (30 derniers jours)
        $boosted = Order::query()->where('created_at', '>=', $since)->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value])
            ->where('utm->utm_campaign', 'like', 'boost-%')->get(['id', 'total', 'utm']);
        // Par produit : seulement les liens d'un produit (« boost-12 »), pas ceux du catalogue ou d'un carrousel
        $byProduct = $boosted->groupBy(fn (Order $o) => (int) substr((string) ($o->utm['utm_campaign'] ?? ''), 6))->forget(0);
        $titles = Product::whereIn('id', $byProduct->keys())->pluck('title', 'id');

        return response()->json(['data' => [
            'available' => MetaSocial::enabled() && $features->enabled('meta_connect', $store),
            'connection' => [
                'connected' => $account !== null && $account->status === 'active',
                'business' => $account?->name,
                'expired' => $account?->token_expires_at?->isPast() ?? false,
                'error' => $account?->last_error,
                'pixel_id' => $store->marketingId('meta_pixel_id'),
                'catalog_id' => $store->marketingId('meta_catalog_id'),
                'catalog_items' => $features->enabled('meta_catalog', $store) ? count($feed->rows($store)) : null,
                'conversions_ready' => $ads->conversionsToken($store) !== null,
                'conversions_sent' => Order::where('conversion_sent_at', '>=', now()->subDays(7))->count(),
            ],
            // Vérification du pixel : null si Meta n'a pas pu être interrogé
            'pixel' => $stats === null ? null : collect(self::EVENTS)->map(fn ($label, $event) => ['event' => $event, 'label' => $label, 'count' => (int) ($stats[$event] ?? 0)])->values()->all(),
            'suggested_id' => $suggested,
            'products' => $products->values()->map(function (Product $p) use ($store, $sold, $info) {
                $price = (int) $p->variants->min('price');
                $link = $store->url('/produits/'.$p->slug).'?'.http_build_query(['utm_source' => 'facebook', 'utm_medium' => 'boost', 'utm_campaign' => 'boost-'.$p->id]);

                return [
                    'id' => $p->id,
                    'title' => $p->title,
                    'image' => $p->images->first()?->urls()['medium'] ?? null,
                    'image_large' => $p->images->first()?->urls()['large'] ?? null,
                    'price' => $price,
                    'sold' => (int) ($sold[$p->id] ?? 0),
                    'stock' => $info[$p->id]['stock'],
                    'margin' => $info[$p->id]['margin'],
                    'link' => $link,
                    'text' => "✨ {$p->title} est disponible ! ".($price > 0 ? Money::format($price).'. ' : '')."Livraison rapide chez toi. Commande ici 👇\n{$link}",
                ];
            })->all(),
            'results' => [
                'orders' => $boosted->count(),
                'amount' => (int) $boosted->sum('total'),
                'products' => $byProduct->map(fn ($orders, $id) => ['id' => $id, 'title' => $titles[$id] ?? 'Produit', 'orders' => $orders->count(), 'amount' => (int) $orders->sum('total')])
                    ->sortByDesc('orders')->values()->all(),
            ],
        ]]);
    }

    /**
     * Trois textes de pub écrits par l'IA (une action) pour un produit, une sélection ou le catalogue.
     */
    public function texts(Request $request, BoostTextWriter $writer): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['product', 'products', 'catalog'])],
            'product_ids' => ['exclude_if:kind,catalog', 'required', 'array', 'min:1', 'max:10'],
            'product_ids.*' => ['integer'],
            'collection_id' => ['nullable', 'integer'],
        ]);
        $collection = $data['kind'] === 'catalog' && ! empty($data['collection_id']) ? Collection::findOrFail($data['collection_id']) : null;
        $products = match (true) {
            $data['kind'] !== 'catalog' => Product::whereIn('id', $data['product_ids'])->where('status', 'active')->get(),
            $collection !== null => $collection->products()->where('status', 'active')->limit(15)->get(),
            default => Product::where('status', 'active')->latest('id')->limit(15)->get(),
        };
        abort_if($products->isEmpty(), 422, 'Aucun produit en ligne à présenter.');

        return response()->json(['data' => $writer->propose($this->currentStore(), $request->user(), $data['kind'], $products, $collection?->title)]);
    }
}
