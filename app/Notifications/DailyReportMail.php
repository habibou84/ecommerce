<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Rapport quotidien d'une boutique, par email.
 */
class DailyReportMail extends Notification
{
    public function __construct(public readonly string $storeName, public readonly string $text) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = explode("\n", $this->text);
        $message = (new MailMessage)->subject('Rapport du jour · '.$this->storeName)->greeting(array_shift($lines));
        foreach ($lines as $line) {
            $message->line($line);
        }

        return $message;
    }
}
