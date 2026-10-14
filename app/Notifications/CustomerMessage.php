<?php

namespace App\Notifications;

use App\Models\Conversation;
use Illuminate\Support\Str;

/**
 * Message d'un client sur le WhatsApp de la boutique (boîte « Messages »).
 */
class CustomerMessage extends StoreNotification
{
    public function __construct(private readonly Conversation $conversation, private readonly string $text)
    {
        parent::__construct($conversation->store_id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'customer_message',
            'title' => '💬 '.($this->conversation->contact_name ?: 'Message WhatsApp'),
            'body' => Str::limit($this->text, 140),
            'url' => '/gestion/messages/'.$this->conversation->id,
        ];
    }
}
