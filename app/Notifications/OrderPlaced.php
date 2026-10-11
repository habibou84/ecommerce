<?php

namespace App\Notifications;

use App\Models\Order;
use Jibiat\Socle\Money;

class OrderPlaced extends StoreNotification
{
    public function __construct(private readonly Order $order)
    {
        parent::__construct($order->store_id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'order_placed',
            'title' => 'Nouvelle commande '.$this->order->reference(),
            'body' => trim(($this->order->customer_name ?? $this->order->customer_phone).' · '.Money::format($this->order->total).' · '.$this->order->source->label()),
            'url' => '/gestion/commandes/'.$this->order->id,
        ];
    }
}
