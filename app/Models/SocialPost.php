<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Publication d'un produit sur un ou plusieurs réseaux (tout de suite ou à l'heure programmée), avec le résultat
 * réseau par réseau.
 */
class SocialPost extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'product_id', 'caption', 'networks', 'square_path', 'vertical_path', 'results', 'status', 'scheduled_at', 'published_at', 'created_by'];

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return ['networks' => 'array', 'results' => 'array', 'scheduled_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ShortLink::class);
    }
}
