<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Article d'un live : variante, code court, prix du live et pièces proposées.
 */
class LiveItem extends Model
{
    use BelongsToStore;

    protected $fillable = ['store_id', 'live_session_id', 'variant_id', 'code', 'price', 'flash_price', 'flash_ends_at', 'quantity', 'position'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'flash_price' => 'integer', 'flash_ends_at' => 'datetime', 'quantity' => 'integer', 'position' => 'integer'];
    }

    /** Vente flash en cours : prix live jusqu'à la fin du chrono */
    public function flashActive(): bool
    {
        return $this->flash_price !== null && $this->flash_ends_at !== null && $this->flash_ends_at->isFuture();
    }

    /**
     * Valeurs de la variante dans l'ordre des options du produit (« M », « Rouge ») ; vide pour un produit sans variantes.
     *
     * @return list<string>
     */
    public function optionValues(): array
    {
        $variant = $this->variant;
        if ($variant === null || $variant->is_default || trim((string) $variant->title) === '') {
            return [];
        }

        return array_values(array_map('trim', explode(' / ', (string) $variant->title)));
    }

    /**
     * Noms des options du produit (« Taille », « Couleur »), dans le même ordre que optionValues().
     *
     * @return list<string>
     */
    public function optionNames(): array
    {
        $variant = $this->variant;

        return collect([$variant?->option1, $variant?->option2, $variant?->option3])
            ->take(count($this->optionValues()))
            ->map(fn ($value) => $value?->option?->name ?? 'Choix')->values()->all();
    }

    /** Prix à payer en ce moment (prix flash pendant le chrono, sinon prix du live) */
    public function currentPrice(): int
    {
        return $this->flashActive() ? (int) $this->flash_price : (int) $this->price;
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LiveReservation::class);
    }
}
