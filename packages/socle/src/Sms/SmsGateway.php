<?php

namespace Jibiat\Socle\Sms;

interface SmsGateway
{
    /**
     * Envoie un SMS et renvoie l'identifiant du message chez le fournisseur.
     *
     * @throws SmsException
     */
    public function send(string $to, string $text): string;
}
