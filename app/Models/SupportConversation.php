<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversation WhatsApp sur le numéro de la plateforme (support), un fil par numéro.
 */
class SupportConversation extends Model
{
    protected $fillable = ['contact', 'contact_name', 'last_preview', 'last_message_at', 'last_inbound_at', 'unread_count'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_inbound_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class);
    }

    public function canReply(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(Conversation::REPLY_WINDOW_HOURS));
    }
}
