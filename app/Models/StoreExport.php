<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Export complet des données d'une boutique.
 */
class StoreExport extends Model
{
    use BelongsToStore;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['store_id', 'user_id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'expires_at' => 'datetime', 'size' => 'integer'];
    }
}
