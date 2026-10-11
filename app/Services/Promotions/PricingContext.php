<?php

namespace App\Services\Promotions;

use App\Models\Discount;
use App\Models\VolumePrice;
use Illuminate\Support\Collection;

/**
 * Données chargées une fois pour tarifer plusieurs produits (grille, panier, commande).
 */
final class PricingContext
{
    /**
     * @param  Collection<int, Discount>  $flash  ventes flash en cours
     * @param  array<int, list<int>>  $collections  collections de chaque produit
     * @param  array<int, Collection<int, VolumePrice>>  $tiers  paliers de quantité par produit
     */
    public function __construct(
        public readonly Collection $flash,
        public readonly array $collections,
        public readonly array $tiers,
    ) {}
}
