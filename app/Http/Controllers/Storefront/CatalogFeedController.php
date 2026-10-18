<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Marketing\CatalogFeed;
use Illuminate\Http\Response;

/**
 * Flux du catalogue lu par Meta (catalogue Facebook, Instagram, WhatsApp) ou Google Merchant, par son lien secret.
 */
class CatalogFeedController extends Controller
{
    public function __invoke(string $token, CatalogFeed $feed): Response
    {
        $store = $this->currentStore();
        abort_unless(filled($store->catalog_feed_token) && hash_equals($store->catalog_feed_token, $token), 404);

        return response($feed->csv($store), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
