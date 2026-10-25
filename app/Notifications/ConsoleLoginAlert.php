<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail à l'administrateur à chaque connexion à la console : s'il n'en est pas l'auteur, il change son mot de passe.
 */
class ConsoleLoginAlert extends Notification
{
    public function __construct(private readonly string $when, private readonly ?string $ip, private readonly ?string $device, private readonly string $url, private readonly ?string $mailer = null, private readonly ?string $from = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Nouvelle connexion à la console Jibiat Marchand')
            ->greeting('Connexion à la console')
            ->line("Le {$this->when}, depuis l'adresse ".($this->ip ?? 'inconnue').'.')
            ->line('Appareil : '.($this->device ?? 'inconnu'))
            ->line('C\'est vous ? Il n\'y a rien à faire.')
            ->line('Ce n\'est pas vous ? Changez tout de suite votre mot de passe dans Mon compte.')
            ->action('Ouvrir la console', $this->url);
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat Marchand');
        }

        return $message;
    }
}
