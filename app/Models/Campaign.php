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

    protected $attributes = ['status' => 'draft', 'recipients' => 0, 'estimated_cost' => 0];

    protected $fillable = ['store_id', 'title', 'message', 'link', 'audience', 'created_by'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'sent_at' => 'datetime', 'recipients' => 'integer', 'estimated_cost' => 'integer'];
    }
}
