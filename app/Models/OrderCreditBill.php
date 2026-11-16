<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** Jour compté des commandes WhatsApp hors live (récap, catalogue, modèles) : commandes, retirées du solde, détail par canal */
class OrderCreditBill extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'day', 'orders', 'amount', 'detail'];

    protected function casts(): array
    {
        return ['day' => 'date', 'orders' => 'integer', 'amount' => 'integer', 'detail' => 'array'];
    }
}
