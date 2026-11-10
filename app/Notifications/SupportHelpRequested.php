<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use App\Support\MailText;
use App\Support\Tenancy;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Quelqu'un attend un humain sur le WhatsApp du support (« aide », ou le robot d'inscription passe la main) : e-mail et
 * notification sur les téléphones de l'équipe de la console, avec le lien vers la conversation.
 */
class SupportHelpRequested extends Notification
{
    public function __construct(
        private readonly string $who,
        private readonly string $reason,
        private readonly bool $mail = false,
        private readonly ?string $mailer = null,
        private readonly ?string $from = null,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->mail ? ['mail'] : [PushChannel::class];
    }

    /** @return array{title: string, body: string, url: string, kind: string} */
    public function toArray(object $notifiable): array
    {
        return ['title' => '🙋 Aide demandée · '.$this->who, 'body' => $this->reason, 'url' => Tenancy::consoleUrl('/console/whatsapp'), 'kind' => 'support_help'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Aide demandée sur WhatsApp · '.$this->who)
            ->greeting('Quelqu\'un attend une réponse sur WhatsApp')
            ->line('Contact : '.MailText::plain($this->who))
            ->line(MailText::plain($this->reason))
            ->line('Le robot s\'est mis en pause : répondez depuis la console ou le téléphone du support.')
            ->action('Ouvrir la conversation', Tenancy::consoleUrl('/console/whatsapp'));
        if ($this->mailer !== null) {
            $message->mailer($this->mailer);
        }
        if ($this->from !== null) {
            $message->from($this->from, 'Jibiat Marchand');
        }

        return $message;
    }
}
