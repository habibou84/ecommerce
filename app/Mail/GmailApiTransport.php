<?php

namespace App\Mail;

use App\Services\Mail\GoogleMail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Transport Laravel « gmail_api » : le message composé par Laravel part par l'API Gmail du compte connecté.
 */
class GmailApiTransport extends AbstractTransport
{
    public function __construct(private readonly GoogleMail $gmail)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->gmail->send($message->toString());
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }
}
