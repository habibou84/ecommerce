<?php

namespace App\Jobs;

use App\Models\SocialPost;
use App\Services\Social\SocialPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Publication immédiate d'un produit sur les réseaux (hors de la requête : les réseaux peuvent être lents).
 */
class PublishSocialPost implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $postId) {}

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::withoutGlobalScopes()->with('store')->find($this->postId);
        if ($post !== null) {
            $publisher->publish($post);
        }
    }
}
