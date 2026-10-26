<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail au validateur des paiements : nouvelle commande de matériel, ou paiement Wave annoncé.
 */
class MaterielOrderToHandle extends Notification
{
    /**
     * @param  array{number: string, store: string, total: string, method: string, payer: ?string, lines: list<string>, delivery: string, console_url: string, claimed: bool}  $details
     */
    public function __construct(private readonly array $details, private readonly ?string $mailer = null, private readonly ?string $from = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->details;
        $message = (new MailMessage)
            ->subject(($d['claimed'] ? 'Paiement Wave annoncé' : 'Commande de matériel')." : {$d['total']} · {$d['number']}")
            ->greeting($d['claimed'] ? 'L\'e-commerçant indique avoir payé par Wave.' : 'Nouvelle commande de matériel.')
            ->line("Espace marchand : {$d['store']}")
            ->line('Articles : '.implode(', ', $d['lines']))
            ->line("Livraison : {$d['delivery']}")
            ->line("Paiement : {$d['method']}".($d['payer'] ? " (numéro {$d['payer']})" : ''))
            ->line("Total : {$d['total']}")
            ->action('Ouvrir la console', $d['console_url']);
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat Marchand');
        }

        return $message;
    }
}
