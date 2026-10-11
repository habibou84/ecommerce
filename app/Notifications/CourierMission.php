<?php

namespace App\Notifications;

use App\Models\Shipment;
use Jibiat\Socle\Money;

/**
 * Nouvelle mission pour un livreur (affichée dans son application).
 */
class CourierMission extends StoreNotification
{
    public function __construct(private readonly Shipment $shipment)
    {
        parent::__construct($shipment->store_id);
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->shipment->order;

        return [
            'kind' => 'courier_mission',
            'title' => 'Nouvelle livraison '.$order->reference(),
            'body' => trim(($order->shipping_place_label ?? '').' · '.Money::format($this->shipment->cod_amount).' à encaisser'),
            'url' => '/livreur/missions/'.$this->shipment->id,
        ];
    }
}
