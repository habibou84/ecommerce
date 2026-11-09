<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Produit du catalogue. Tout ce qui se vend, se stocke et s'expédie est une variante :
 * un produit simple a une seule variante, « par défaut ».
 */
class Product extends Model
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $fillable = [
        'store_id', 'title', 'slug', 'description', 'brand', 'status', 'is_visible_online', 'has_variants',
        'track_inventory', 'allow_backorder', 'requires_shipping', 'is_fragile', 'low_stock_threshold',
        'seo_title', 'seo_description', 'published_at', 'preorder_enabled', 'preorder_ship_date', 'preorder_deposit_percent',
        'sale_lot', 'min_order_quantity', 'deposit_type', 'deposit_value',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'is_visible_online' => 'boolean',
            'has_variants' => 'boolean',
            'track_inventory' => 'boolean',
            'allow_backorder' => 'boolean',
            'requires_shipping' => 'boolean',
            'is_fragile' => 'boolean',
            'is_bundle' => 'boolean',
            'preorder_enabled' => 'boolean',
            'preorder_ship_date' => 'date',
            'published_at' => 'datetime',
            'sale_lot' => 'integer',
            'min_order_quantity' => 'integer',
            'deposit_value' => 'integer',
        ];
    }

    /**
     * Quantité vendable la plus proche de celle demandée : au moins le minimum, et un multiple du lot quand l'article
     * se vend par lot seulement (4 demandées, lot de 6 : 6). Jamais moins que demandé.
     */
    public function sellableQuantity(int $quantity): int
    {
        $quantity = max($quantity, (int) $this->min_order_quantity, 1);
        $lot = (int) $this->sale_lot;

        return $lot > 1 ? (int) (ceil($quantity / $lot) * $lot) : $quantity;
    }

    /**
     * Acompte demandé pour une ligne de cet article (total de la ligne, pièces) : pourcentage ou montant fixe par
     * pièce ; ancien réglage « acompte de précommande » repris s'il n'y en a pas d'autre. 0 sans acompte.
     */
    public function depositFor(int $lineTotal, int $quantity): int
    {
        $type = $this->deposit_type;
        $value = (int) $this->deposit_value;
        if ($type === null && $this->preorder_enabled && $this->preorder_deposit_percent) {
            [$type, $value] = ['percent', (int) $this->preorder_deposit_percent];
        }

        return match ($type) {
            'percent' => (int) round($lineTotal * min(100, $value) / 100),
            'fixed' => min($lineTotal, $value * max(1, $quantity)),
            default => 0,
        };
    }

    /** « par lot de 6 », « 6 pièces minimum », ou null si l'article se vend à la pièce */
    public function lotLabel(): ?string
    {
        return match (true) {
            (int) $this->sale_lot > 1 => 'par lot de '.$this->sale_lot,
            (int) $this->min_order_quantity > 1 => $this->min_order_quantity.' pièces minimum',
            default => null,
        };
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_products')->withPivot('position');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Prix minimum et maximum des variantes actives (listes, tri).
     */
    public function refreshPriceRange(): void
    {
        $prices = $this->variants()->where('is_active', true)->pluck('price');

        $this->forceFill(['price_min' => $prices->min(), 'price_max' => $prices->max()])->saveQuietly();
    }
}
