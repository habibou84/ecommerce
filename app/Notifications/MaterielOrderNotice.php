<?php

namespace App\Notifications;

/**
 * Commande de matériel : confirmée, en préparation, en livraison, livrée, annulée, paiement reçu.
 */
class MaterielOrderNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'materiel', 'title' => $this->title, 'body' => $this->body, 'url' => '/gestion/materiel'];
    }
}
