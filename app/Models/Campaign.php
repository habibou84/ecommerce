<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Relance WhatsApp d'anciens clients (modèle marketing « Nouveautés » de Meta).
 */
class Campaign extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'title', 'message', 'link', 'audience', 'created_by'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'sent_at' => 'datetime', 'recipients' => 'integer', 'estimated_cost' => 'integer'];
    }
}
