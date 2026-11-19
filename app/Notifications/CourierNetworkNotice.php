<?php

namespace App\Notifications;

/**
 * Demande d'une boutique à un livreur inscrit, et sa réponse (acceptée, refusée, boutique quittée).
 */
class CourierNetworkNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body, private readonly string $url)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'courier_network', 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
