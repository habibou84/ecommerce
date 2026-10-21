<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message d'une conversation du support : reçu, envoyé depuis la console, ou depuis l'application du téléphone.
 */
class SupportMessage extends Model
{
    protected $fillable = ['support_conversation_id', 'direction', 'type', 'body', 'provider_message_id', 'status', 'error', 'user_id', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
