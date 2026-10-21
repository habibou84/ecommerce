<?php

namespace App\Notifications;

use App\Models\CourierRemittanceDeclaration;
use Jibiat\Socle\Money;

/**
 * Réponse de la boutique au versement déclaré par le livreur : confirmé (avec l'écart éventuel) ou refusé.
 */
class RemittanceDecided extends StoreNotification
{
    public function __construct(private readonly CourierRemittanceDeclaration $declaration)
    {
        parent::__construct($declaration->store_id);
    }

    public function toArray(object $notifiable): array
    {
        $d = $this->declaration;
        $difference = $d->remittance?->difference ?? 0;

        return [
            'kind' => 'remittance_'.$d->status,
            'title' => $d->status === 'confirmed'
                ? 'Versement confirmé : '.Money::format($d->remittance->received_amount)
                : 'Versement refusé : '.Money::format($d->amount),
            'body' => $d->status === 'confirmed'
                ? ($difference ? ($difference < 0 ? 'Manque ' : 'Excédent ').Money::format(abs($difference)) : 'Aucun écart ✓')
                : ($d->reject_reason ?? 'Voyez avec le marchand.'),
            'url' => '/livreur/caisse',
        ];
    }
}
