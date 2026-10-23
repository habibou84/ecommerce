<?php

namespace App\Notifications;

use App\Models\Shipment;

/**
 * Expédition à traiter : refusée par le transporteur, échec de livraison, colis perdu ou retourné.
 */
class ShipmentAlert extends StoreNotification
{
    public function __construct(private readonly Shipment $shipment, private readonly string $title, private readonly ?string $detail = null)
    {
        parent::__construct($shipment->store_id);
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->shipment->order;

        return [
            'kind' => 'shipment_'.$this->shipment->status->value,
            'title' => $this->title.' · '.$order->reference(),
            'body' => trim(($this->shipment->connection?->name ?? $this->shipment->courier?->name ?? 'Entreprise de livraison').($this->detail ? ' : '.$this->detail : '')),
            'url' => '/gestion/commandes/'.$order->id,
        ];
    }
}
