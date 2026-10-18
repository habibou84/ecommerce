<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ShortLink;
use Illuminate\Http\RedirectResponse;

/**
 * Lien court partagé ({slug}.jibiat-marchand.com/p/{code}) : compté, puis vers la fiche produit avec l'origine.
 */
class ShortLinkController extends Controller
{
    public function __invoke(string $code): RedirectResponse
    {
        $store = $this->currentStore();
        $link = ShortLink::forStore($store->id)->where('code', $code)->first();
        $product = $link?->target_type === 'product' ? Product::forStore($store->id)->find($link->target_id) : null;
        if ($product === null) {
            return redirect('/');
        }

        $link->increment('clicks');
        // Lien d'une publication sur un réseau : la commande sera rattachée au réseau et à la publication
        $origin = $link->network
            ? ['utm_source' => $link->network, 'utm_medium' => 'social', 'utm_campaign' => 'post-'.$link->social_post_id]
            : ['utm_source' => 'partage', 'utm_medium' => 'lien_court', 'utm_campaign' => $code];

        return redirect('/produits/'.$product->slug.'?'.http_build_query($origin));
    }
}
