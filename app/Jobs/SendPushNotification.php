<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\PushSubscription;
use App\Services\Push\FirebaseMessaging;
use App\Services\Push\WebPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Notification push sur les appareils d'une personne (application Android, navigateurs), en file d'attente. L'adresse d'un appareil où
 * l'application n'est plus installée est oubliée.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(public int $userId, public string $title, public string $body, public array $data = []) {}

    public function handle(FirebaseMessaging $firebase, WebPush $webPush): void
    {
        // Navigateurs et applications installées depuis le navigateur (un abonnement expiré est oublié par WebPush)
        if (WebPush::enabled()) {
            foreach (PushSubscription::where('user_id', $this->userId)->get() as $subscription) {
                $webPush->send($subscription, ['title' => $this->title, 'body' => $this->body, 'url' => $this->data['url'] ?? '', 'tag' => ($this->data['kind'] ?? '') ?: null]);
            }
        }
        if (! FirebaseMessaging::enabled()) {
            return;
        }
        foreach (DeviceToken::where('user_id', $this->userId)->get() as $device) {
            try {
                if (! $firebase->send($device->token, $this->title, $this->body, $this->data)) {
                    $device->delete();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
