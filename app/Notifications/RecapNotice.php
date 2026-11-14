<?php

namespace App\Notifications;

/**
 * Commande prise par « récap » dans une conversation WhatsApp : récap envoyé, confirmé, ou pas envoyé (et pourquoi).
 */
class RecapNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body, private readonly string $url)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'whatsapp_recap', 'title' => str_starts_with($this->title, '✅') ? $this->title : '🧾 '.$this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
