<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'ouverture de compte chez une entreprise de Jibiat, par la connexion directe (contrat socle 1.12) :
 * en attente de l'entreprise, de la confirmation du marchand déjà client, puis connectée, refusée ou expirée.
 */
class CarrierRequest extends Model
{
    use BelongsToStore;

    /** En cours : l'entreprise ou le marchand doit encore répondre */
    public const OPEN = ['pending', 'confirmation'];

    protected $fillable = ['store_id', 'user_id', 'request_id', 'slug', 'name', 'url', 'status', 'decide_before', 'reason', 'waiting_orders', 'connection_id', 'decided_at'];

    protected function casts(): array
    {
        return [
            'decide_before' => 'datetime',
            'decided_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'waiting_orders' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CarrierConnection::class, 'connection_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
