<?php

namespace App\Services\Marketing;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Promotions\Pricing;
use App\Services\Storefront\StorefrontCatalog;
use App\Services\Storefront\StorefrontView;
use App\Support\SpreadsheetCell;
use Illuminate\Support\Str;

/**
 * Flux du catalogue au format attendu par Meta (catalogue Facebook, Instagram et WhatsApp) et Google Merchant :
 * une ligne par variante en vente, avec prix (et prix barré d'une vente flash), disponibilité, photo et lien.
 * Meta relit le lien régulièrement : prix et stock restent à jour sans rien ressaisir.
 */
class CatalogFeed
{
    public const COLUMNS = ['id', 'item_group_id', 'title', 'description', 'availability', 'condition', 'price', 'sale_price', 'link', 'image_link',
        'additional_image_link', 'brand', 'quantity_to_sell_on_facebook', 'size', 'color'];

    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly StorefrontView $view,
        private readonly Pricing $pricing,
    ) {}

    public function token(Store $store, bool $renew = false): string
    {
        if ($renew || blank($store->catalog_feed_token)) {
            $store->forceFill(['catalog_feed_token' => Str::random(32)])->save();
        }

        return $store->catalog_feed_token;
    }

    public function url(Store $store): string
    {
        return $this->view->baseUrl($store).'/flux/catalogue/'.$this->token($store).'.csv';
    }

    /**
     * @return list<array<string, string>>
     */
    public function rows(Store $store): array
    {
        $base = $this->view->baseUrl($store);
        $products = $this->catalog->products($store)
            ->with(['images' => fn ($q) => $q->orderBy('position'), 'options', 'variants' => fn ($q) => $q->where('is_active', true)->with(['option1', 'option2', 'option3', 'image'])])
            ->orderBy('id')->get();
        $availability = $this->catalog->availability($store, $products->flatMap->variants->pluck('id')->all());
        $context = $this->pricing->context($store->id, $products->pluck('id')->all());

        $rows = [];
        foreach ($products as $product) {
            $images = $product->images->map(fn ($i) => $this->absolute($base, $i->urls()['large']))->values();
            $description = trim(Str::limit(preg_replace('/\s+/', ' ', strip_tags((string) $product->description)), 4900, '…'));
            foreach ($product->variants as $variant) {
                $variant->setRelation('product', $product);
                $sellable = $this->catalog->sellable($variant, $availability);
                $price = $this->pricing->unit($context, $variant);
                $regular = max($variant->price, (int) ($price['compare_at'] ?? 0));
                $options = $this->options($product, $variant);
                $image = $variant->image ? $this->absolute($base, $variant->image->urls()['large']) : $images->first();

                $rows[] = [
                    'id' => (string) ($variant->sku ?: 'V'.$variant->id),
                    'item_group_id' => $product->has_variants ? 'P'.$product->id : '',
                    'title' => Str::limit($variant->fullTitle(), 150, ''),
                    'description' => $description !== '' ? $description : $product->title,
                    'availability' => $sellable === null || $sellable > 0 ? 'in stock' : 'out of stock',
                    'condition' => 'new',
                    'price' => $regular.' '.$store->currency,
                    'sale_price' => $price['price'] < $regular ? $price['price'].' '.$store->currency : '',
                    'link' => $base.'/produits/'.$product->slug,
                    'image_link' => (string) $image,
                    'additional_image_link' => $images->reject(fn ($u) => $u === $image)->take(10)->implode(','),
                    'brand' => (string) ($product->brand ?: $store->name),
                    'quantity_to_sell_on_facebook' => $sellable === null ? '' : (string) $sellable,
                    'size' => $options['size'] ?? '',
                    'color' => $options['color'] ?? '',
                ];
            }
        }

        // Meta refuse un article sans photo
        return array_values(array_filter($rows, fn ($r) => $r['image_link'] !== ''));
    }

    public function csv(Store $store): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::COLUMNS);
        foreach ($this->rows($store) as $row) {
            // Une cellule qui commence par =, +, - ou @ n'est pas lue comme une formule si le fichier est ouvert dans un tableur
            fputcsv($out, SpreadsheetCell::row(array_map(fn ($c) => $row[$c], self::COLUMNS)));
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Taille et couleur d'après le nom des options (« Taille », « Pointure », « Couleur »…).
     *
     * @return array<string, string>
     */
    private function options(Product $product, ProductVariant $variant): array
    {
        $found = [];
        foreach ($product->options->sortBy('position')->values() as $i => $option) {
            $value = $variant->{'option'.($i + 1)}?->value;
            $name = Str::lower(Str::ascii((string) $option->name));
            if ($value === null) {
                continue;
            }
            if (Str::contains($name, ['taille', 'size', 'pointure'])) {
                $found['size'] ??= $value;
            } elseif (Str::contains($name, ['couleur', 'color', 'coloris'])) {
                $found['color'] ??= $value;
            }
        }

        return $found;
    }

    private function absolute(string $base, string $url): string
    {
        return str_starts_with($url, 'http') ? $url : $base.'/'.ltrim($url, '/');
    }
}
