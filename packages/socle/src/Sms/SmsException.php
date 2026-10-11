<?php

namespace Jibiat\Socle\Sms;

use RuntimeException;
use Throwable;

/**
 * Échec d'envoi d'un SMS. « retryable » : panne passagère (réseau, erreur 5xx, quota 429), l'envoi peut être retenté.
 */
class SmsException extends RuntimeException
{
    public function __construct(string $message = '', public readonly bool $retryable = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
