<?php

namespace App\Notifications;

use App\Models\ProductVariant;

class LowStock extends StoreNotification
{
    public function __construct(private readonly ProductVariant $variant, private readonly int $available)
    {
        parent::__construct($variant->store_id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->available <= 0 ? 'out_of_stock' : 'low_stock',
            'title' => $this->available <= 0 ? 'Rupture de stock' : 'Stock bas',
            'body' => $this->variant->fullTitle().' : '.max(0, $this->available).' disponible'.($this->available > 1 ? 's' : ''),
            'url' => '/gestion/catalogue/'.$this->variant->product_id,
        ];
    }
}
