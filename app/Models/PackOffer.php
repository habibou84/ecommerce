<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Pack au choix : « 3 articles au choix pour 25 000 F », parmi une catégorie (collection) ou des articles choisis.
 * Le prix s'applique tout seul dès que le client a assez d'articles de la sélection (boutique, saisie, live).
 */
class PackOffer extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'title', 'quantity', 'price', 'collection_id', 'is_active'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price' => 'integer', 'is_active' => 'boolean'];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'pack_offer_product');
    }

    /**
     * Produits concernés (ceux de la catégorie, ou ceux choisis).
     *
     * @return list<int>
     */
    public function productIds(): array
    {
        return $this->collection_id !== null
            ? $this->collection?->products()->pluck('products.id')->all() ?? []
            : $this->products()->pluck('products.id')->all();
    }
}
