<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail du code de connexion à la console.
 */
class ConsoleLoginCode extends Notification
{
    public function __construct(public readonly string $code, private readonly int $minutes, private readonly ?string $mailer = null, private readonly ?string $from = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("{$this->code} : votre code de connexion à la console Jibiat Marchand")
            ->greeting('Votre code de connexion')
            ->line("**{$this->code}**")
            ->line("Saisissez-le dans la console. Il est valable {$this->minutes} minutes et ne sert qu'une fois.")
            ->line('Vous n\'êtes pas en train de vous connecter ? Changez tout de suite votre mot de passe : quelqu\'un le connaît.');
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat Marchand');
        }

        return $message;
    }
}
