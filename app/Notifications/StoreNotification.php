<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\StoreDatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Notification interne d'une boutique : cloche de la gestion, et push sur les téléphones où l'application est
 * installée.
 */
abstract class StoreNotification extends Notification
{
    public function __construct(public readonly int $storeId) {}

    public function via(object $notifiable): array
    {
        return [StoreDatabaseChannel::class, PushChannel::class];
    }

    /**
     * @return array{title: string, body: string, url: string, kind: string}
     */
    abstract public function toArray(object $notifiable): array;
}
