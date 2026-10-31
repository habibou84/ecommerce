<?php

namespace App\Notifications\Channels;

use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\PushSubscription;
use App\Models\Store;
use App\Services\Couriers\SharedCouriers;
use App\Services\Push\FirebaseMessaging;
use App\Services\Push\WebPush;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Même alerte que la cloche, sur le téléphone ou l'ordinateur (application Android, navigateur, application
 * installée depuis le navigateur) : au toucher, l'application ouvre la page
 * de la notification dans la bonne boutique.
 */
class PushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        // Application Android (Firebase), navigateur ou application installée depuis le navigateur (Web Push)
        $phone = FirebaseMessaging::enabled() && DeviceToken::where('user_id', $notifiable->getKey())->exists();
        $browser = WebPush::enabled() && PushSubscription::where('user_id', $notifiable->getKey())->exists();
        if (! $phone && ! $browser) {
            return;
        }

        $content = $notification->toArray($notifiable);
        $store = isset($notification->storeId) ? Store::find($notification->storeId) : null;
        // Une personne de plusieurs boutiques sait de laquelle il s'agit
        $title = $store && $notifiable->stores()->count() > 1 ? $store->name.' · '.$content['title'] : $content['title'];

        // Notification du livreur : elle ouvre son espace d'accueil, qui réunit les courses de toutes ses boutiques
        $target = $store;
        if ($store && str_starts_with((string) ($content['url'] ?? ''), '/livreur')) {
            $target = SharedCouriers::homeOf((int) $notifiable->getKey()) ?? $store;
        }

        SendPushNotification::dispatch((int) $notifiable->getKey(), Str::limit($title, 100), Str::limit((string) $content['body'], 240), [
            'url' => $target ? $target->url($content['url'] ?? '/gestion') : (string) ($content['url'] ?? ''),
            'kind' => (string) ($content['kind'] ?? ''),
        ])->afterCommit();
    }
}
