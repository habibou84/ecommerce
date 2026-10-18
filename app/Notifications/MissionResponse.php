<?php

namespace App\Notifications;

use App\Models\Shipment;

/**
 * Suite d'une mission confiée à un livreur : refusée, ou toujours sans réponse.
 */
class MissionResponse extends StoreNotification
{
    public function __construct(private readonly Shipment $shipment, private readonly string $title, private readonly ?string $detail = null, private readonly ?string $url = null)
    {
        parent::__construct($shipment->store_id);
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->shipment->order;

        return [
            'kind' => 'mission_response',
            'title' => $this->title.' · '.$order->reference(),
            'body' => trim(($order->shipping_place_label ?? '').($this->detail ? ' · '.$this->detail : ''), ' ·'),
            'url' => $this->url ?? '/gestion/commandes/'.$order->id,
        ];
    }
}
