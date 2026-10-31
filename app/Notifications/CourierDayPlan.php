<?php

namespace App\Notifications;

/**
 * Résumé du matin pour le livreur : ses courses du jour (y compris celles en retard) et l'argent à encaisser.
 */
class CourierDayPlan extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'courier_day', 'title' => $this->title, 'body' => $this->body, 'url' => '/livreur'];
    }
}
