<?php

namespace App\Services\Catalog;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\Stock\StockKeeper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Reprise du catalogue d'un marchand qui utilisait la mini-boutique d'une entreprise de livraison (Delivery App) :
 * lecture de GET /api/public/v1/products avec sa clé API (portée stock:read). La clé n'est pas conservée.
 *
 * Les produits deviennent des produits simples ; le stock gardé chez le marchand est repris dans l'emplacement
 * principal. Le stock déposé à l'entrepôt de l'entreprise sera suivi par la connexion transporteur (phase 4).
 */
class DeliveryAppImporter
{
    public function __construct(
        private readonly CatalogWriter $writer,
        private readonly StockKeeper $stock,
    ) {}

    /**
     * Adresse de l'API à partir de ce que saisit le marchand (« entreprise1.jibiat.com » ou une URL complète).
     * Seuls les domaines de la plateforme Delivery App sont acceptés (pas de requête vers une adresse quelconque).
     */
    public function apiUrl(string $address): string
    {
        $address = trim($address);
        $parts = parse_url(str_contains($address, '://') ? $address : "https://{$address}") ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));

        $allowed = collect(config('platform.delivery_app_domains'))
            ->contains(fn (string $domain) => $host === $domain || str_ends_with($host, ".{$domain}"));

        if (! $allowed) {
            throw new BusinessRuleException('Indiquez l\'adresse de votre entreprise de livraison, par exemple entreprise.jibiat.com.', 'address');
        }

        // HTTPS en production ; DELIVERY_APP_SCHEME=http et un port seulement pour une instance de développement
        $scheme = config('platform.delivery_app_scheme', 'https');
        $port = isset($parts['port']) && $scheme === 'http' ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}/api/public/v1";
    }

    /**
     * @return list<array{id: int, sku: ?string, name: string, price: int, available: int, levels: list<array<string, mixed>>}>
     */
    public function fetch(string $address, string $apiKey): array
    {
        $url = $this->apiUrl($address).'/products';
        $products = [];
        $page = 1;

        do {
            try {
                $response = Http::withToken($apiKey)->acceptJson()->timeout(20)->get($url, ['per_page' => 200, 'page' => $page]);
            } catch (ConnectionException) {
                throw new BusinessRuleException('L\'entreprise de livraison ne répond pas. Vérifiez l\'adresse.', 'address');
            }

            match ($response->status()) {
                200 => null,
                401 => throw new BusinessRuleException('Clé API refusée par l\'entreprise de livraison.', 'api_key'),
                403 => throw new BusinessRuleException('Cette clé n\'a pas l\'accès « Lire les produits et le stock » (stock:read).', 'api_key'),
                404 => throw new BusinessRuleException('Aucune entreprise de livraison à cette adresse.', 'address'),
                default => throw new BusinessRuleException('Réponse inattendue de l\'entreprise de livraison ('.$response->status().').'),
            };

            $products = array_merge($products, $response->json('data') ?? []);
            $hasNext = $response->json('links.next') !== null && $page < 50;
            $page++;
        } while ($hasNext);

        return $products;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array{created: int, skipped: int, at_carrier: int}
     */
    public function import(Store $store, array $products, ?User $actor): array
    {
        $summary = ['created' => 0, 'skipped' => 0, 'at_carrier' => 0];
        $location = $this->stock->defaultLocation($store);

        DB::transaction(function () use ($store, $products, $actor, $location, &$summary) {
            foreach ($products as $item) {
                $sku = filled($item['sku'] ?? null) ? (string) $item['sku'] : null;

                // Déjà importé (même SKU) : on n'y touche pas
                if ($sku !== null && ProductVariant::forStore($store->id)->where('sku', $sku)->exists()) {
                    $summary['skipped']++;

                    continue;
                }

                $product = $this->writer->save($store, null, [
                    'title' => (string) $item['name'],
                    'variants' => [['sku' => $sku, 'price' => max(0, (int) ($item['price'] ?? 0))]],
                ], $actor);

                $levels = collect($item['levels'] ?? []);
                $atMerchant = (int) $levels->whereNull('hub_id')->sum('on_hand');
                $summary['at_carrier'] += (int) $levels->whereNotNull('hub_id')->sum('on_hand');

                if ($atMerchant > 0) {
                    $this->stock->setOnHand($actor, $product->variants->first()->setRelation('product', $product), $location, $atMerchant, StockMovementType::Import, 'Import Delivery App');
                }
                $summary['created']++;
            }
        });

        return $summary;
    }
}
