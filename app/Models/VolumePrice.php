<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Prix par quantité : « 3 pour 25 000 F » (toutes variantes du produit confondues).
 */
class VolumePrice extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'product_id', 'min_quantity', 'price'];

    protected function casts(): array
    {
        return ['min_quantity' => 'integer', 'price' => 'integer'];
    }
}
