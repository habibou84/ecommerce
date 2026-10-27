<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot de clés d'accès : cartes « Membre Premium » à vie (numérotées) ou clés à durée créées dans la console, et
 * cartes Membre Premium offertes par une entreprise de livraison de Jibiat (« partner » : un lot par entreprise et par
 * formule, mois offerts puis réduction tant que le marchand livre avec elle).
 */
class AccessKeyBatch extends Model
{
    /** Lots créés depuis la console */
    public const KINDS = ['founder' => 'Membre Premium (à vie)', 'timed' => 'Durée limitée'];

    public const PARTNER = 'partner';

    public const LABELS = self::KINDS + [self::PARTNER => 'Membre Premium offerte par une entreprise de livraison'];

    protected $fillable = ['kind', 'partner_slug', 'plan_id', 'months', 'discount_rate', 'quantity', 'distributor_name', 'distributor_phone', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['months' => 'integer', 'quantity' => 'integer', 'discount_rate' => 'float'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function keys(): HasMany
    {
        return $this->hasMany(AccessKey::class, 'batch_id');
    }

    public function isFounder(): bool
    {
        return $this->kind === 'founder';
    }

    public function isPartner(): bool
    {
        return $this->kind === self::PARTNER;
    }

    /**
     * Carte Membre Premium (à vie ou offerte par une entreprise), par opposition à une clé à durée.
     */
    public function isPremium(): bool
    {
        return $this->isFounder() || $this->isPartner();
    }
}
