<?php

namespace App\Notifications;

use App\Models\CourierRemittanceDeclaration;
use Jibiat\Socle\Money;

/**
 * Un livreur déclare avoir versé ses espèces : à confirmer à la caisse.
 */
class RemittanceDeclared extends StoreNotification
{
    public function __construct(private readonly CourierRemittanceDeclaration $declaration)
    {
        parent::__construct($declaration->store_id);
    }

    public function toArray(object $notifiable): array
    {
        $d = $this->declaration;

        return [
            'kind' => 'remittance_declared',
            'title' => "Versement déclaré par {$d->courier->name} : ".Money::format($d->amount),
            'body' => CourierRemittanceDeclaration::METHODS[$d->method].($d->reference ? " · réf. {$d->reference}" : '').' · à confirmer',
            'url' => '/gestion/caisse/livreurs',
        ];
    }
}
