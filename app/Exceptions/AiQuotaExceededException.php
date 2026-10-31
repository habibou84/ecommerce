<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Plus d'actions de l'assistant IA (offre du mois et recharges) : l'application affiche le message en rouge et
 * propose la fenêtre de recharge directement (code « ai_quota » et état de l'assistant dans la réponse).
 */
class AiQuotaExceededException extends QuotaExceededException
{
    /**
     * @param  array<string, mixed>  $status  état de l'assistant (Assistant::status)
     */
    public function __construct(string $message, private readonly array $status)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'errors' => (object) [], 'code' => 'ai_quota', 'ai' => $this->status], 422);
    }
}
