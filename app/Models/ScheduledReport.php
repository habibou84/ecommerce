<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapport envoyé à un membre de l'équipe (chaque jour, par WhatsApp ou par email).
 */
class ScheduledReport extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'user_id', 'frequency', 'channel', 'send_at', 'is_active'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
