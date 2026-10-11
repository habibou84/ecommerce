<?php

namespace App\Notifications;

use App\Notifications\Channels\StoreDatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Notification interne d'une boutique (cloche de la gestion). Le push sur téléphone viendra avec l'application
 * installable ; WhatsApp en phase 6.
 */
abstract class StoreNotification extends Notification
{
    public function __construct(public readonly int $storeId) {}

    public function via(object $notifiable): array
    {
        return [StoreDatabaseChannel::class];
    }

    /**
     * @return array{title: string, body: string, url: string, kind: string}
     */
    abstract public function toArray(object $notifiable): array;
}
