<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lieu de la boutique (commune, quartier) → zone du transporteur. Sans zone : lieu non couvert.
 */
class CarrierZoneMapping extends Model
{
    protected $fillable = ['connection_id', 'place_id', 'carrier_zone_id', 'matched_by'];

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function carrierZone(): BelongsTo
    {
        return $this->belongsTo(CarrierZone::class);
    }
}
