<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** Jour compté des commandes WhatsApp : commandes du jour, et combien ont été retirées du solde (amount) */
class LiveSessionBill extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'live_session_id', 'day', 'orders', 'offered', 'amount'];

    protected function casts(): array
    {
        return ['day' => 'date', 'orders' => 'integer', 'offered' => 'integer', 'amount' => 'integer'];
    }
}
