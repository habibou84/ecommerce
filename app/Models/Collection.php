<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Collection de produits (fait aussi office de catégorie).
 */
class Collection extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'title', 'slug', 'description', 'image_path', 'sort_order', 'is_visible_online', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['is_visible_online' => 'boolean', 'rules' => 'array'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_products')->withPivot('position')->orderByPivot('position');
    }
}
