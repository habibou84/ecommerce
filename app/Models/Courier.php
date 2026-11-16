<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Livreur de la boutique. Invité par un lien ; une fois son compte créé, il utilise l'application /livreur.
 */
class Courier extends Model
{
    use BelongsToStore;

    public const VEHICLES = ['moto' => 'Moto', 'bicycle' => 'Vélo', 'car' => 'Voiture', 'foot' => 'À pied'];

    protected $fillable = ['store_id', 'user_id', 'name', 'phone', 'vehicle_type', 'pay_rule_id', 'cash_limit', 'is_available', 'status', 'note'];

    protected $attributes = ['status' => 'invited', 'is_available' => true, 'vehicle_type' => 'moto'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'cash_limit' => 'integer', 'last_latitude' => 'float', 'last_longitude' => 'float', 'last_located_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payRule(): BelongsTo
    {
        return $this->belongsTo(CourierPayRule::class, 'pay_rule_id');
    }

    /**
     * Lieux couverts (ville, commune ou quartier). Aucun : il couvre partout.
     */
    public function places(): BelongsToMany
    {
        return $this->belongsToMany(Place::class, 'courier_places')->withPivot('store_id');
    }

    /**
     * Le livreur couvre-t-il ce lieu de livraison ? Sans lieu coché, il couvre partout (même une commande sans lieu
     * précis) ; sinon le lieu doit être un de ses lieux ou s'y trouver (Angré est dans Cocody, dans Abidjan).
     */
    public function coversPlace(?Place $place): bool
    {
        if ($this->places->isEmpty()) {
            return true;
        }

        return $place !== null && array_intersect($place->lineageIds(), $this->places->pluck('id')->all()) !== [];
    }

    /**
     * Missions confiées (historique, y compris refusées) : sert au « chacun son tour ».
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(CourierAssignment::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function collections(): HasMany
    {
        return $this->hasMany(CashCollection::class);
    }

    /**
     * Argent encaissé et pas encore versé à la boutique.
     */
    public function cashHeld(): int
    {
        return (int) $this->collections()->whereNull('remittance_id')->where('method', 'cash')->sum('amount');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
