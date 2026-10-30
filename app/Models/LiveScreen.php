<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écran du live (télé, tablette, ordinateur) : créé sans boutique avec un code à 4 chiffres, relié ensuite par la
 * vendeuse ; son jeton secret lui donne l'affichage du live en cours de la boutique, sans compte.
 */
class LiveScreen extends Model
{
    protected $fillable = ['store_id', 'token', 'code', 'name', 'paired_at', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['paired_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subSeconds(30));
    }
}
