<?php

namespace App\Enums;

enum StockLocationType: string
{
    case Shop = 'shop';
    case Warehouse = 'warehouse';
    case Home = 'home';
    case CarrierHub = 'carrier_hub';

    public function label(): string
    {
        return match ($this) {
            self::Shop => 'Boutique',
            self::Warehouse => 'Entrepôt',
            self::Home => 'Domicile',
            self::CarrierHub => 'Entrepôt d\'une entreprise de livraison',
        };
    }

    /**
     * Types qu'on crée à la main (l'entrepôt d'un transporteur vient de sa connexion, phase 4).
     *
     * @return list<string>
     */
    public static function manualValues(): array
    {
        return [self::Shop->value, self::Warehouse->value, self::Home->value];
    }
}
