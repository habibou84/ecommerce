<?php

namespace App\Services\Couriers;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Places\PlaceLocator;

/**
 * Où placer une course sur la carte du livreur, du plus précis au moins précis :
 * le point GPS de la commande, celui gardé sur l'adresse du client (enregistré par un livreur), le centre du
 * quartier (ou de la commune). Sans rien de tout cela, la course n'a pas de point.
 */
class CourierPoints
{
    /**
     * @return array{lat: float, lng: float, exact: bool}|null
     */
    public static function of(Shipment $s): ?array
    {
        $order = $s->order;
        if ($order === null) {
            return null;
        }
        if ($order->shipping_latitude !== null && $order->shipping_longitude !== null) {
            return ['lat' => (float) $order->shipping_latitude, 'lng' => (float) $order->shipping_longitude, 'exact' => true];
        }
        $saved = self::savedAddress($order);
        if ($saved !== null) {
            return ['lat' => (float) $saved->latitude, 'lng' => (float) $saved->longitude, 'exact' => true];
        }
        $area = PlaceLocator::pointOf($order->place);

        return $area ? ['lat' => $area[0], 'lng' => $area[1], 'exact' => false] : null;
    }

    /** Adresse du client avec un point GPS, au même quartier (la même adresse écrite d'abord) */
    public static function savedAddress(Order $order): ?CustomerAddress
    {
        if ($order->customer_id === null || $order->shipping_place_id === null) {
            return null;
        }

        return CustomerAddress::withoutGlobalScopes()->where('store_id', $order->store_id)->where('customer_id', $order->customer_id)
            ->where('place_id', $order->shipping_place_id)->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderByRaw('CASE WHEN address_line = ? THEN 0 ELSE 1 END', [(string) $order->shipping_address])->latest('updated_at')->first();
    }
}
