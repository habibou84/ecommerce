<?php

namespace App\Notifications;

/**
 * Boost : la pub a commencé, Meta l'a refusée, ou elle est terminée.
 */
class BoostNotice extends StoreNotification
{
    public function __construct(int $storeId, private readonly string $title, private readonly string $body)
    {
        parent::__construct($storeId);
    }

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'boost', 'title' => $this->title, 'body' => $this->body, 'url' => '/gestion/boost'];
    }
}
