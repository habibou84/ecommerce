<?php

namespace App\Jobs;

use App\Models\WhatsappSignup;
use App\Services\WhatsApp\SignupByWhatsApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
        $signup = WhatsappSignup::with('store.owner')->find($this->signupId);
        if ($signup !== null) {
            $signups->draftProduct($signup, $this->mediaId);
        }
    }

    /** Échec imprévu (délai dépassé…) : la personne n'attend pas pour rien, elle reçoit le lien pour ajouter l'article */
    public function failed(?Throwable $e): void
    {
        $signup = WhatsappSignup::with('store')->find($this->signupId);
        if ($signup !== null) {
            rescue(fn () => app(SignupByWhatsApp::class)->photoFailed($signup), report: false);
        }
    }
}
