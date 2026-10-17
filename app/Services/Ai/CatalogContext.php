<?php

namespace App\Services\Ai;

use App\Enums\ProductStatus;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\Places\PlaceMatcher;
use App\Services\Stock\StockLevels;
use Illuminate\Support\Collection;

/**
 * Ce que l'assistant sait de la boutique : catalogue (codes des variantes, prix, disponibilité) et zones de
 * livraison. Un grand catalogue est réduit aux produits dont un mot du nom est cité dans la conversation.
 */
class CatalogContext
{
    public const LIMIT = 300;

    public function __construct(private readonly StockLevels $levels) {}

    /**
     * @return Collection<int, Product>
     */
    public function products(Store $store, string $text): Collection
    {
        $query = fn () => Product::forStore($store->id)->where('status', ProductStatus::Active->value)
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('position')]);

        if ($query()->count() <= self::LIMIT || trim($text) === '') {
            return $query()->latest('updated_at')->limit(self::LIMIT)->get();
        }

        $words = array_filter(explode(' ', PlaceMatcher::normalize($text)), fn ($w) => mb_strlen($w) >= 3);

        return $query()->where(fn ($q) => collect($words)->take(40)->each(fn ($w) => $q->orWhere('title', 'ilike', '%'.$w.'%')))
            ->limit(self::LIMIT)->get();
    }

    /**
     * Une ligne par produit : « V12 Sac en pagne — 9 000 F » ou « P3 Robe wax : V4 S 15 000 F ; V5 M 16 000 F ».
     * Avec le stock, chaque variante suivie indique « épuisé » ou le nombre disponible.
     */
    public function describe(Store $store, Collection $products, bool $withStock = false): string
    {
        if ($products->isEmpty()) {
            return '(catalogue vide)';
        }

        $available = $withStock
            ? $this->levels->variantAvailability($store)->whereIn('v.product_id', $products->pluck('id'))->get()->pluck('available', 'variant_id')
            : collect();
        $line = function (ProductVariant $v, ?string $label) use ($available) {
            $stock = $available->has($v->id) ? ((int) $available[$v->id] > 0 ? " ({$available[$v->id]} dispo)" : ' (épuisé)') : '';

            return trim("V{$v->id} ".($label ?? '').' '.number_format($v->price, 0, ',', ' ').' F'.$stock);
        };

        return $products->map(function (Product $p) use ($line) {
            if (! $p->has_variants && $p->variants->count() === 1) {
                return $line($p->variants[0], "{$p->title} —");
            }

            return "P{$p->id} {$p->title} : ".$p->variants->map(fn ($v) => $line($v, $v->title))->implode(' ; ');
        })->implode("\n");
    }

    /**
     * « Cocody (Riviera, Angré…) : 1 500 F, offerte dès 30 000 F, 24 h »
     */
    public function zones(Store $store): string
    {
        $zones = DeliveryZone::forStore($store->id)->where('is_active', true)->with('places:id,name')->orderBy('sort')->get();
        if ($zones->isEmpty()) {
            return '(pas de zones de livraison définies)';
        }

        return $zones->map(function (DeliveryZone $z) {
            $places = $z->places->pluck('name')->take(8)->implode(', ').($z->places->count() > 8 ? '…' : '');

            return trim("{$z->name}".($places !== '' ? " ({$places})" : '').' : '.number_format((int) $z->fee, 0, ',', ' ').' F'
                .($z->free_above !== null ? ', offerte dès '.number_format((int) $z->free_above, 0, ',', ' ').' F' : '')
                .($z->eta_label ? ", {$z->eta_label}" : ''));
        })->implode("\n");
    }
}
