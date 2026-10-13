<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message d'une conversation : reçu du client (in) ou envoyé par la boutique (out : réponse ou message automatique).
 */
class ConversationMessage extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'conversation_id', 'direction', 'type', 'body', 'provider_message_id', 'status', 'error', 'user_id', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
