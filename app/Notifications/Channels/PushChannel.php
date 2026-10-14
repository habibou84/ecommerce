<?php

namespace App\Notifications\Channels;

use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\Store;
use App\Services\Push\FirebaseMessaging;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Même alerte que la cloche, sur le téléphone (application installée) : au toucher, l'application ouvre la page
 * de la notification dans la bonne boutique.
 */
class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! FirebaseMessaging::enabled() || ! DeviceToken::where('user_id', $notifiable->getKey())->exists()) {
            return;
        }

        $content = $notification->toArray($notifiable);
        $store = isset($notification->storeId) ? Store::find($notification->storeId) : null;
        // Une personne de plusieurs boutiques sait de laquelle il s'agit
        $title = $store && $notifiable->stores()->count() > 1 ? $store->name.' · '.$content['title'] : $content['title'];

        SendPushNotification::dispatch((int) $notifiable->getKey(), Str::limit($title, 100), Str::limit((string) $content['body'], 240), [
            'url' => $store ? $store->url($content['url'] ?? '/gestion') : (string) ($content['url'] ?? ''),
            'kind' => (string) ($content['kind'] ?? ''),
        ])->afterCommit();
    }
}
