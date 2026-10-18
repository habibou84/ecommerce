<?php

namespace App\Notifications;

use App\Models\Shipment;

/**
 * Alerte sur une mission du livreur (ex. le client a répondu « Pas disponible »).
 */
class CourierAlert extends StoreNotification
{
    public function __construct(private readonly Shipment $shipment, private readonly string $title, private readonly ?string $detail = null)
    {
        parent::__construct($shipment->store_id);
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->shipment->order;

        return [
            'kind' => 'courier_alert',
            'title' => $this->title.' · '.$order?->reference(),
            'body' => trim(($order?->shipping_place_label ?? '').($this->detail ? ' · '.$this->detail : ''), ' ·'),
            'url' => '/livreur/missions/'.$this->shipment->id,
        ];
    }
}
