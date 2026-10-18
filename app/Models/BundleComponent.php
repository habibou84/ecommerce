<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article contenu dans un lot (quantité par lot vendu).
 */
class BundleComponent extends Model
{
    use BelongsToStore;

    public $timestamps = false;

    protected $fillable = ['store_id', 'bundle_variant_id', 'component_variant_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'component_variant_id');
    }
}
