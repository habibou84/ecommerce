<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Règle métier non respectée : réponse 422 au format des erreurs de validation,
 * rattachée à un champ du formulaire si possible.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->field ? [$this->field => [$this->getMessage()]] : (object) [],
        ], 422);
    }
}
