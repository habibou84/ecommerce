<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Le modèle n'a pas répondu (réseau, saturation, refus, réponse illisible).
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly int $inputTokens = 0, public readonly int $outputTokens = 0)
    {
        parent::__construct($message);
    }
}
