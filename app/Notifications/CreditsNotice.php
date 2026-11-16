<?php

namespace App\Notifications;

/**
 * Solde de commandes : bientôt vide ou à zéro (commandes WhatsApp comptées le soir).
 */
class CreditsNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'credits', 'title' => '💳 '.$this->title, 'body' => $this->body, 'url' => '/gestion/solde'];
    }
}
