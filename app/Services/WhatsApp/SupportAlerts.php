<?php

namespace App\Services\WhatsApp;

use App\Models\SupportConversation;
use App\Models\User;
use App\Notifications\SupportHelpRequested;
use App\Services\Billing\BillingSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Une personne attend un humain sur le WhatsApp du support : la conversation passe en tête de la console (« Aide
 * demandée ») et l'équipe est prévenue tout de suite (notification sur téléphone, e-mail), une fois par demi-heure
 * et par contact.
 */
class SupportAlerts
{
    public function helpRequested(string $phone, string $reason): void
    {
        $contacts = array_values(array_unique(array_filter([PhoneNumber::normalize($phone), PhoneNumber::modernize($phone), $phone])));
        SupportConversation::whereIn('contact', $contacts)->update(['help_requested_at' => now()]);
        if (! Cache::add('support-help-alert:'.($contacts[0] ?? $phone), true, now()->addMinutes(30))) {
            return;
        }
        $name = SupportConversation::whereIn('contact', $contacts)->value('contact_name');
        $who = trim(($name ? $name.' · ' : '').PhoneNumber::display($contacts[0] ?? $phone));

        $admins = User::where('is_super_admin', true)->where('status', 'active')->get();
        try {
            Notification::send($admins, new SupportHelpRequested($who, $reason));
        } catch (Throwable $e) {
            Log::warning('Notification « aide demandée » non envoyée', ['error' => $e->getMessage()]);
        }
        $mailer = app(BillingSettings::class)->mailer();
        $emails = $admins->pluck('email')->filter()->values();
        if ($mailer !== null && $emails->isNotEmpty()) {
            try {
                Notification::route('mail', $emails->all())->notifyNow(new SupportHelpRequested($who, $reason, true, $mailer['mailer'], $mailer['from']));
            } catch (Throwable $e) {
                Log::warning('E-mail « aide demandée » non envoyé', ['error' => $e->getMessage()]);
            }
        }
    }
}
