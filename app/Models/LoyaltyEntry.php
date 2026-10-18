<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Mouvement de points de fidélité d'un client.
 */
class LoyaltyEntry extends Model
{
    use BelongsToStore;

    public const UPDATED_AT = null;

    public const TYPES = [
        'earned' => 'Commande livrée',
        'redeemed' => 'Utilisés sur une commande',
        'refunded' => 'Rendus (commande annulée)',
        'reversed' => 'Retirés (commande retournée)',
        'referral' => 'Parrainage',
        'adjustment' => 'Ajustement',
    ];

    protected $fillable = ['store_id', 'customer_id', 'points', 'type', 'order_id', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }
}
