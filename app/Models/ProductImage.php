<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Services\Catalog\ImageStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'product_id', 'path', 'alt', 'width', 'height', 'position'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array{thumb: string, medium: string, large: string}
     */
    public function urls(): array
    {
        return app(ImageStore::class)->urls($this->path);
    }
}
