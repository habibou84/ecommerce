<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avis d'un client sur un produit reçu. Rien n'est affiché sur la boutique avant que le marchand le publie.
 */
class ProductReview extends Model
{
    use BelongsToStore;

    public const STATUSES = ['pending' => 'À valider', 'published' => 'Publié', 'hidden' => 'Masqué'];

    protected $fillable = ['store_id', 'product_id', 'order_id', 'customer_id', 'author_name', 'rating', 'comment', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'published_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
