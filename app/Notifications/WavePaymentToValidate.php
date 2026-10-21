<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail au validateur : paiement Wave annoncé ou déclaré payé par l'e-commerçant, à vérifier puis confirmer.
 */
class WavePaymentToValidate extends Notification
{
    /**
     * @param  array{amount: string, payer: ?string, store: ?string, label: string, number: string, claimed: bool, validate_url: string, console_url: string}  $details
     */
    public function __construct(private readonly array $details) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;

        return (new MailMessage)
            ->subject(($d['claimed'] ? 'Paiement Wave déclaré payé' : 'Paiement Wave en cours')." : {$d['amount']} · {$d['number']}")
            ->greeting($d['claimed'] ? 'L\'e-commerçant indique avoir payé.' : 'Un e-commerçant va payer par Wave.')
            ->line("Montant : {$d['amount']}")
            ->line('Numéro Wave : '.($d['payer'] ?? 'non indiqué'))
            ->line('Espace marchand : '.($d['store'] ?? '—'))
            ->line("Objet : {$d['label']} ({$d['number']})")
            ->line('Vérifiez dans Wave que le montant est bien arrivé, puis confirmez.')
            ->action('Vérifier et confirmer', $d['validate_url'])
            ->line("Ou depuis la console : {$d['console_url']}");
    }
}
