<?php

namespace App\Services\Storefront;

use App\Models\Collection;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Services\Catalog\ImageStore;
use Illuminate\Http\Request;

/**
 * Données communes à toutes les pages de la boutique : thème, menus, logo, panier, adresse canonique.
 */
class StorefrontView
{
    public function __construct(
        private readonly ThemeSettings $themes,
        private readonly CartService $carts,
        private readonly ImageStore $images,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function shared(Request $request, Store $store): array
    {
        $cart = $this->carts->find($request, $store);

        return [
            'store' => $store,
            'theme' => $this->themes->resolve($store),
            'logo' => $store->logo_path ? $this->images->urls($store->logo_path)['thumb'] : null,
            'menus' => ['main' => $this->menu($store, 'main'), 'footer' => $this->menu($store, 'footer')],
            'cartCount' => $cart ? (int) $cart->items()->sum('quantity') : 0,
            'baseUrl' => $this->baseUrl($store),
            'whatsapp' => $store->whatsapp_phone ? 'https://wa.me/'.ltrim($store->whatsapp_phone, '+') : null,
        ];
    }

    /**
     * Adresse publique : le domaine personnalisé principal s'il est vérifié, sinon le sous-domaine.
     */
    public function baseUrl(Store $store): string
    {
        $domain = StoreDomain::where('store_id', $store->id)->where('is_primary', true)->whereNotNull('verified_at')->value('hostname');

        return $domain ? 'https://'.$domain : rtrim($store->url(), '/');
    }

    /**
     * Menu enregistré, sinon menu par défaut (accueil, boutique, collections ; pages en pied de page).
     *
     * @return list<array{label: string, url: string}>
     */
    public function menu(Store $store, string $handle): array
    {
        $items = Menu::forStore($store->id)->where('handle', $handle)->value('items');

        if ($items === null) {
            $items = $handle === 'main'
                ? [['label' => 'Accueil', 'type' => 'home'], ['label' => 'Boutique', 'type' => 'products'],
                    ...Collection::forStore($store->id)->where('is_visible_online', true)->orderBy('title')->limit(4)->get()
                        ->map(fn ($c) => ['label' => $c->title, 'type' => 'collection', 'target' => $c->id])->all()]
                : Page::forStore($store->id)->where('is_visible', true)->orderBy('title')->get()
                    ->map(fn ($p) => ['label' => $p->title, 'type' => 'page', 'target' => $p->id])->all();
        } else {
            $items = json_decode(is_string($items) ? $items : json_encode($items), true) ?? [];
        }

        return array_values(array_filter(array_map(fn ($item) => $this->link($store, $item), $items)));
    }

    /**
     * @param  array{label?: string, type?: string, target?: mixed}  $item
     * @return array{label: string, url: string}|null
     */
    private function link(Store $store, array $item): ?array
    {
        $url = match ($item['type'] ?? null) {
            'home' => '/',
            'products' => '/produits',
            'collection' => ($slug = Collection::forStore($store->id)->whereKey($item['target'] ?? 0)->value('slug')) ? "/collections/{$slug}" : null,
            'page' => ($slug = Page::forStore($store->id)->where('is_visible', true)->whereKey($item['target'] ?? 0)->value('slug')) ? "/pages/{$slug}" : null,
            'url' => is_string($item['target'] ?? null) && preg_match('#^(https?://|/)#', $item['target']) ? $item['target'] : null,
            default => null,
        };

        return $url && filled($item['label'] ?? null) ? ['label' => $item['label'], 'url' => $url] : null;
    }
}
