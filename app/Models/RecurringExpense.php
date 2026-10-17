<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Dépense qui revient chaque mois (loyer, salaires, abonnement internet…) : enregistrée automatiquement
 * comme dépense de la boutique le jour choisi.
 */
class RecurringExpense extends Model
{
    use BelongsToStore;

    protected $attributes = ['is_active' => true];

    protected $fillable = ['store_id', 'category', 'channel', 'amount', 'method', 'note', 'day_of_month', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'day_of_month' => 'integer', 'is_active' => 'boolean', 'last_run_on' => 'date'];
    }
}
