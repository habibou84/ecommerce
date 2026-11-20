<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quartier absent du référentiel, signalé par une boutique : la plateforme l'ajoute à la liste commune (socle), pour
 * toutes les boutiques et toutes les entreprises de Jibiat, ou refuse.
 */
class PlaceReport extends Model
{
    public const STATUSES = ['pending', 'added', 'rejected'];

    protected $fillable = ['store_id', 'user_id', 'name', 'parent_id', 'status'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'parent_id');
    }
}
