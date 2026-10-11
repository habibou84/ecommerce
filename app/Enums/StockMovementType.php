<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Receipt = 'receipt';
    case Adjustment = 'adjustment';
    case Count = 'count';
    case Damaged = 'damaged';
    case Reservation = 'reservation';
    case Release = 'release';
    case Shipment = 'shipment';
    case Return = 'return';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Import = 'import';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Réception',
            self::Adjustment => 'Ajustement',
            self::Count => 'Inventaire',
            self::Damaged => 'Abîmé / perdu',
            self::Reservation => 'Réservation',
            self::Release => 'Libération',
            self::Shipment => 'Expédition',
            self::Return => 'Retour',
            self::TransferOut => 'Transfert (sortie)',
            self::TransferIn => 'Transfert (entrée)',
            self::Import => 'Import',
        };
    }
}
