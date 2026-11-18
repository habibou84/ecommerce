<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Passage de l'assistant du support sur un message WhatsApp : ce qu'il a fait et ce que ça a coûté.
 */
class SupportAgentRun extends Model
{
    /** L'assistant a répondu */
    public const ANSWERED = 'answered';

    /** Il a passé la main à l'équipe (question sans fiche, argent, personne mécontente, demande d'un humain) */
    public const HANDOFF = 'handoff';

    /** Plafond de coût du jour atteint : l'équipe répond */
    public const BUDGET = 'budget';

    /** L'assistant n'a pas pu répondre (panne) : l'équipe est prévenue */
    public const FAILED = 'failed';

    protected $fillable = ['support_conversation_id', 'support_message_id', 'outcome', 'reason', 'articles', 'model', 'input_tokens', 'output_tokens', 'cost_usd'];

    protected function casts(): array
    {
        return ['articles' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_usd' => 'float'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }
}
