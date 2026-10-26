<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Matériel proposé aux e-commerçants (Console › Matériel) : stock de la plateforme ou fournisseur partenaire.
 */
class Accessory extends Model
{
    public const CATEGORIES = [
        'impression' => 'Étiquettes et impression',
        'live' => 'Vendre en live',
        'caisse' => 'Caisse',
        'consommables' => 'Consommables',
    ];

    protected $fillable = [
        'name', 'category', 'description', 'price', 'cost_price', 'stock', 'supplier', 'partner_name', 'partner_phone',
        'commission_type', 'commission_value', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'cost_price' => 'integer',
            'stock' => 'integer',
            'commission_value' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk(config('filesystems.catalog_disk', 'public'))->url($this->image_path) : null;
    }

    /**
     * Ce que rapporte une pièce : marge (stock de la plateforme, prix d'achat connu) ou commission du partenaire.
     */
    public function earningPerUnit(): ?int
    {
        if ($this->supplier === 'partner') {
            return match ($this->commission_type) {
                'percent' => (int) round($this->price * ($this->commission_value ?? 0) / 100),
                'amount' => (int) $this->commission_value,
                default => null,
            };
        }

        return $this->cost_price !== null ? $this->price - $this->cost_price : null;
    }
}
