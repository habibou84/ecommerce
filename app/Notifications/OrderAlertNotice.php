<?php

namespace App\Notifications;

/**
 * Alerte sur des commandes qui attendent (sans livreur, pas ramassées, à confirmer, point du matin…).
 * Ouvre la liste des commandes déjà filtrée.
 */
class OrderAlertNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $kind, private readonly string $title, private readonly string $body, private readonly string $url)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'order_alert_'.$this->kind, 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
