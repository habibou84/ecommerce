<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Profil d'un livreur inscrit au lien commun : le sien, partagé par toutes ses boutiques (chaque boutique garde sa
 * fiche Courier : paie, plafond d'espèces, courses, argent).
 */
class CourierProfile extends Model
{
    public const VEHICLES = Courier::VEHICLES;

    protected $fillable = ['user_id', 'vehicle_type', 'listed'];

    protected function casts(): array
    {
        return ['listed' => 'boolean', 'id_document_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'courier_profile_places');
    }

    public function hasIdDocument(): bool
    {
        return $this->id_document_path !== null;
    }

    /** « Moussa T. » : prénom et initiale (annuaire, avant acceptation) */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->user?->name)) ?: [];
        $first = $parts[0] ?? 'Livreur';

        return count($parts) > 1 ? $first.' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.' : $first;
    }
}
