<?php

namespace App\Notifications;

/**
 * Abonnement : essai bientôt terminé, échéance, retard de paiement, passage à l'offre gratuite, paiement reçu.
 */
class BillingNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'billing', 'title' => $this->title, 'body' => $this->body, 'url' => '/gestion/abonnement'];
    }
}
