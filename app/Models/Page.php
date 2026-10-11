<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Page libre de la boutique en ligne (À propos, Livraison et retours, CGV…).
 */
class Page extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'title', 'slug', 'body', 'is_visible', 'seo_title', 'seo_description'];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }
}
