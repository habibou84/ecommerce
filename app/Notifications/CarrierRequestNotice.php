<?php

namespace App\Notifications;

/**
 * Connexion directe : l'entreprise a ouvert le compte (les commandes qui l'attendaient lui sont confiées), l'a refusé
 * ou n'a pas répondu à temps (elles reviennent « à assigner »).
 */
class CarrierRequestNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body, private readonly string $url)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'carrier_request', 'title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
