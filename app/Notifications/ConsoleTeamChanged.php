<?php

namespace App\Notifications;

use App\Support\Tenancy;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail à toute l'équipe de la console quand un administrateur est ajouté ou retiré.
 */
class ConsoleTeamChanged extends Notification
{
    public function __construct(private readonly string $what, private readonly ?string $ip, private readonly ?string $mailer = null, private readonly ?string $from = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Équipe de la console modifiée · Jibiat Marchand')
            ->greeting('Équipe de la console modifiée')
            ->line($this->what)
            ->line('Depuis l\'adresse '.($this->ip ?? 'inconnue').'.')
            ->line('Ce changement n\'était pas prévu ? Ouvrez la console, retirez l\'accès et changez votre mot de passe.')
            ->action('Voir l\'équipe', Tenancy::consoleUrl('/console/equipe'));
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat Marchand');
        }

        return $message;
    }
}
