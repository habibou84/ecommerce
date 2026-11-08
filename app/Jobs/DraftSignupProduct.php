<?php

namespace App\Jobs;

use App\Services\WhatsApp\SignupByWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Photo envoyée à la fin de l'inscription par WhatsApp : fiche produit préparée par l'IA, hors de la notification
 * de Meta (qui attend une réponse rapide).
 */
class DraftSignupProduct implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $signupId, public string $mediaId) {}

    public function handle(SignupByWhatsApp $signups): void
    {
        $signup = SignupByWhatsApp::with('store.owner')->find($this->signupId);
        if ($signup !== null) {
            $signups->draftProduct($signup, $this->mediaId);
        }
    }
}
