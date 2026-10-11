<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ancien sous-domaine d'une boutique, redirigé vers le nouveau jusqu'à expires_at.
 */
class SlugHistory extends Model
{
    public const KEEP_DAYS = 90;

    protected $table = 'slug_history';

    protected $fillable = ['store_id', 'old_slug', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
