<?php

namespace App\Enums;

enum PaymentKind: string
{
    case Deposit = 'deposit';
    case Manual = 'manual';
    case CodCash = 'cod_cash';
    case CodMobile = 'cod_mobile';
    case Online = 'online';
    case CarrierCod = 'carrier_cod';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Acompte',
            self::Manual => 'Paiement',
            self::CodCash => 'Encaissé à la livraison',
            self::CodMobile => 'Mobile money à la livraison',
            self::Online => 'Paiement en ligne',
            self::CarrierCod => 'Encaissé par le transporteur',
        };
    }
}
