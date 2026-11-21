<?php

namespace App\Notifications;

/**
 * Un livreur de Jibiat rapporte des colis : le marchand confirme la remise (« Tout reçu » ou « Il en manque »).
 */
class ReturnHandoverNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'return_handover', 'title' => $this->title, 'body' => $this->body, 'url' => '/gestion/commandes'];
    }
}
