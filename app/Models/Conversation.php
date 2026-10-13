<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversation avec un client sur un canal de messagerie (WhatsApp) : un fil par numéro.
 */
class Conversation extends Model
{
    use BelongsToStore;

    // Meta n'autorise une réponse libre que dans les 24 h qui suivent le dernier message du client
    public const REPLY_WINDOW_HOURS = 24;

    protected $fillable = ['store_id', 'channel', 'contact', 'contact_name', 'customer_id', 'order_id', 'last_preview', 'last_message_at', 'last_inbound_at', 'unread_count'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_inbound_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function canReply(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(now()->subHours(self::REPLY_WINDOW_HOURS));
    }
}
