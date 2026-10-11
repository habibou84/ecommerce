<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Notification en base rattachée à la boutique concernée : une personne de plusieurs boutiques ne voit, dans
 * chaque gestion, que les notifications de celle-ci.
 */
class StoreDatabaseChannel extends DatabaseChannel
{
    protected function buildPayload($notifiable, Notification $notification)
    {
        return parent::buildPayload($notifiable, $notification) + ['store_id' => $notification->storeId ?? null];
    }
}
