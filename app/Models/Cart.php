<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Panier d'un visiteur de la boutique, retrouvé par le cookie « panier ». Les prix ne sont jamais stockés :
 * ils sont relus sur les variantes à chaque affichage et à la commande.
 */
class Cart extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'token', 'customer_id', 'name', 'phone', 'discount_code', 'utm', 'status', 'last_activity_at', 'order_id'];

    protected function casts(): array
    {
        return ['utm' => 'array', 'last_activity_at' => 'datetime', 'recovery_sent_at' => 'datetime', 'recovered_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }
}
