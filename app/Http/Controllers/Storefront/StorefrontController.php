<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureStorefrontOpen;
use App\Models\Collection;
use App\Models\Page;
use App\Services\Storefront\CartService;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontView;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Pages publiques de la boutique : accueil, catalogue, produit, collection, page libre, sitemap.
 */
class StorefrontController extends Controller
{
    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly StorefrontView $view,
        private readonly CartService $carts,
    ) {}

    /**
     * Racine de chaque adresse : présentation (plateforme), boutique, console.
     */
    public function home(Request $request, Tenancy $tenancy): Response|JsonResponse
    {
        return match ($tenancy->zone()) {
            Tenancy::PLATFORM => response()->view('platform.home', ['signupUrl' => '/creer-ma-boutique', 'loginUrl' => '/connexion']),
            Tenancy::CONSOLE => response()->view('console.home'),
            Tenancy::API => response()->json(['message' => 'API Jibiat Marchand.'], 404),
            default => $this->storefrontHome($request),
        };
    }

    private function storefrontHome(Request $request): Response
    {
        $store = $this->currentStore();
        if (! $store->storefront_enabled && ! EnsureStorefrontOpen::previewing($request)) {
            return response()->view('storefront.coming-soon', ['store' => $store]);
        }

        $shared = $this->view->shared($request, $store);
        $settings = $shared['theme']['settings'];
        $featured = $settings['featured_collection_id'] ? Collection::forStore($store->id)->where('is_visible_online', true)->find($settings['featured_collection_id']) : null;

        return $this->render($request, 'storefront.home', $shared + [
            'featured' => $featured ? ['collection' => $featured, 'cards' => $this->catalog->cards($store, $this->catalog->products($store)
                ->whereHas('collections', fn ($q) => $q->whereKey($featured->id))->limit(8)->get())] : null,
            'newArrivals' => $settings['show_new_arrivals'] ? $this->catalog->cards($store, $this->catalog->products($store)->latest('published_at')->limit(8)->get()) : collect(),
            'collections' => $settings['show_collections'] ? Collection::forStore($store->id)->where('is_visible_online', true)
                ->whereHas('products', fn ($q) => $q->where('status', 'active'))->orderBy('title')->limit(8)->get() : collect(),
            'meta' => ['title' => $store->name.($store->tagline ? ' · '.$store->tagline : ''), 'description' => $store->tagline ?? $store->name, 'path' => '/'],
        ]);
    }

    public function products(Request $request): Response
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tri' => ['nullable', 'in:nouveautes,prix-croissant,prix-decroissant']]);
        $store = $this->currentStore();
        $search = trim((string) $request->query('q'));

        $query = $this->catalog->products($store)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")->orWhere('description', 'ilike', "%{$search}%")));

        return $this->listing($request, $query, $search !== '' ? "Résultats pour « {$search} »" : 'Tous les produits', null, '/produits');
    }

    public function collection(Request $request, string $slug): Response
    {
        $store = $this->currentStore();
        $collection = Collection::forStore($store->id)->where('slug', $slug)->where('is_visible_online', true)->firstOrFail();
        $query = $this->catalog->products($store)->whereHas('collections', fn ($q) => $q->whereKey($collection->id));

        return $this->listing($request, $query, $collection->title, $collection, "/collections/{$slug}");
    }

    public function product(Request $request, string $slug): Response
    {
        $store = $this->currentStore();
        $product = $this->catalog->findProduct($store, $slug) ?? abort(404);
        $availability = $this->catalog->availability($store, $product->variants->pluck('id')->all());

        $variants = $product->variants->map(function ($v) use ($product, $availability) {
            $v->setRelation('product', $product);
            $image = $product->images->firstWhere('id', $v->image_id);

            return [
                'id' => $v->id,
                'options' => array_values(array_filter([$v->option1?->value, $v->option2?->value, $v->option3?->value], fn ($x) => $x !== null)),
                'title' => $v->title,
                'price' => $v->price,
                'compare_at' => $v->compare_at_price && $v->compare_at_price > $v->price ? $v->compare_at_price : null,
                'available' => $this->catalog->sellable($v, $availability),
                'image' => $image ? $image->urls() : null,
            ];
        })->values();

        $first = $variants->first(fn ($v) => $v['available'] === null || $v['available'] > 0) ?? $variants->first();
        $related = $this->catalog->cards($store, $this->catalog->products($store)->whereKeyNot($product->id)
            ->when($product->collections()->exists(), fn ($q) => $q->whereHas('collections', fn ($c) => $c->whereIn('collections.id', $product->collections()->pluck('collections.id'))))
            ->inRandomOrder()->limit(4)->get());
        $shared = $this->view->shared($request, $store);
        $image = $product->images->first()?->urls();

        return $this->render($request, 'storefront.product', $shared + [
            'product' => $product,
            'images' => $product->images->map(fn ($i) => ['id' => $i->id, 'alt' => $i->alt ?? $product->title, 'urls' => $i->urls()])->values(),
            'variants' => $variants,
            'selected' => $first,
            'options' => $product->options->map(fn ($o) => ['name' => $o->name, 'values' => $o->values->pluck('value')->values()])->values(),
            'related' => $related,
            'meta' => [
                'title' => ($product->seo_title ?: $product->title).' · '.$store->name,
                'description' => $product->seo_description ?: Str::limit(strip_tags((string) $product->description), 155) ?: $product->title,
                'image' => $image ? $this->absolute($shared['baseUrl'], $image['large']) : null,
                'path' => "/produits/{$product->slug}",
                'type' => 'product',
            ],
        ]);
    }

    public function page(Request $request, string $slug): Response
    {
        $store = $this->currentStore();
        $page = Page::forStore($store->id)->where('slug', $slug)->where('is_visible', true)->firstOrFail();

        return $this->render($request, 'storefront.page', $this->view->shared($request, $store) + [
            'page' => $page,
            'meta' => ['title' => ($page->seo_title ?: $page->title).' · '.$store->name, 'description' => $page->seo_description ?: Str::limit((string) $page->body, 155), 'path' => "/pages/{$slug}"],
        ]);
    }

    public function sitemap(Request $request): Response
    {
        $store = $this->currentStore();
        $base = $this->view->baseUrl($store);
        $urls = collect(['/', '/produits'])
            ->concat($this->catalog->products($store)->pluck('slug')->map(fn ($s) => "/produits/{$s}"))
            ->concat(Collection::forStore($store->id)->where('is_visible_online', true)->pluck('slug')->map(fn ($s) => "/collections/{$s}"))
            ->concat(Page::forStore($store->id)->where('is_visible', true)->pluck('slug')->map(fn ($s) => "/pages/{$s}"));

        return response()->view('storefront.sitemap', ['urls' => $urls->map(fn ($p) => $base.$p)], 200)->header('Content-Type', 'application/xml');
    }

    public function robots(Tenancy $tenancy): Response
    {
        $store = $tenancy->store();
        $open = $store?->storefront_enabled;
        $lines = $open
            ? ['User-agent: *', 'Disallow: /gestion', 'Disallow: /livreur', 'Disallow: /panier', 'Disallow: /commande', 'Disallow: /suivi', 'Sitemap: '.$this->view->baseUrl($store).'/sitemap.xml']
            : ($tenancy->zone() === Tenancy::PLATFORM ? ['User-agent: *', 'Disallow: /connexion'] : ['User-agent: *', 'Disallow: /']);

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * Aperçu d'une boutique encore fermée (lien signé, valable une heure, créé depuis la gestion).
     */
    public function preview(Request $request): RedirectResponse
    {
        // Signature calculée sur le chemin (l'adresse de la boutique varie) : le lien porte l'identifiant de la boutique
        abort_unless($request->hasValidSignature(false) && (int) $request->query('boutique') === $this->currentStore()->id, 403, 'Lien d\'aperçu invalide ou expiré.');

        return redirect('/')->withCookie(cookie(EnsureStorefrontOpen::PREVIEW_COOKIE, (string) (time() + 3600), 60));
    }

    private function listing(Request $request, $query, string $title, ?Collection $collection, string $path): Response
    {
        $store = $this->currentStore();
        $query = match ($request->query('tri')) {
            'prix-croissant' => $query->orderBy('price_min'),
            'prix-decroissant' => $query->orderByDesc('price_max'),
            default => $collection ? $query->leftJoin('collection_products as cp', fn ($j) => $j->on('cp.product_id', '=', 'products.id')->where('cp.collection_id', $collection->id))
                ->orderBy('cp.position')->select('products.*') : $query->latest('published_at'),
        };
        $page = $query->paginate(24)->withQueryString();

        return $this->render($request, 'storefront.listing', $this->view->shared($request, $store) + [
            'title' => $title,
            'collection' => $collection,
            'cards' => $this->catalog->cards($store, $page->getCollection()),
            'page' => $page,
            'meta' => ['title' => $title.' · '.$store->name, 'description' => $collection?->seo_description ?: ($collection?->description ? Str::limit($collection->description, 155) : $store->tagline ?? $store->name), 'path' => $path],
        ]);
    }

    /**
     * Rendu avec le cookie d'origine de la visite (publicité, lien partagé) s'il est dans l'adresse.
     */
    private function render(Request $request, string $view, array $data): Response
    {
        $utm = $this->carts->utmFromQuery($request);
        $response = response()->view($view, $data);
        if ($utm !== []) {
            $response->withCookie(cookie(CartService::ORIGIN_COOKIE, json_encode($utm), 60 * 24 * 30, '/', null, null, true, false, 'lax'));
        }

        return $response;
    }

    private function absolute(string $base, string $url): string
    {
        return str_starts_with($url, 'http') ? $url : $base.'/'.ltrim($url, '/');
    }
}
