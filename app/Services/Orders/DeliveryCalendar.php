<?php

namespace App\Services\Orders;

use App\Models\CarrierConnection;
use App\Models\Store;
use App\Services\Shipping\ShipmentDesk;
use Illuminate\Support\Carbon;

/**
 * Jours de livraison de la boutique et première date possible : avant l'heure limite, aujourd'hui ; après, le jour
 * suivant ; les jours sans livraison sont sautés. Une entreprise de livraison qui donne sa propre journée (Delivery
 * App : heure limite, jours fermés) l'emporte quand elle est plus tardive.
 */
class DeliveryCalendar
{
    public const DEFAULT_CUTOFF = '14:00';

    /**
     * @return array{cutoff: string, closed_weekdays: list<int>, closed_dates: list<string>}
     */
    public function settings(Store $store): array
    {
        $saved = $store->delivery_calendar ?? [];

        return [
            'cutoff' => $saved['cutoff'] ?? self::DEFAULT_CUTOFF,
            'closed_weekdays' => array_values(array_map('intval', $saved['closed_weekdays'] ?? [])), // 1 = lundi … 7 = dimanche
            'closed_dates' => array_values($saved['closed_dates'] ?? []),
        ];
    }

    public function isOpen(Store $store, Carbon $day): bool
    {
        $settings = $this->settings($store);

        return ! in_array($day->dayOfWeekIso, $settings['closed_weekdays'], true) && ! in_array($day->toDateString(), $settings['closed_dates'], true);
    }

    /**
     * Première date de livraison possible (AAAA-MM-JJ), à l'heure de la boutique.
     */
    public function earliest(Store $store, ?CarrierConnection $carrier = null): string
    {
        $now = now($store->timezone ?: config('app.timezone'));
        [$hour, $minute] = array_map('intval', explode(':', $this->settings($store)['cutoff']));
        $day = $now->copy()->startOfDay();
        if ($now->gte($day->copy()->setTime($hour, $minute))) {
            $day->addDay();
        }
        $date = $this->nextOpen($store, $day)->toDateString();

        // Journée de l'entreprise de livraison (si elle la donne) : on ne propose pas plus tôt qu'elle
        $carrierDate = $carrier?->isApi() ? app(ShipmentDesk::class)->deliveryDay($carrier)['earliest_delivery_date'] ?? null : null;

        return $carrierDate !== null && $carrierDate > $date ? $carrierDate : $date;
    }

    /**
     * Les prochains jours, ouverts ou non, à partir d'aujourd'hui (pour le choix de la date dans le formulaire).
     *
     * @return list<array{date: string, open: bool}>
     */
    public function days(Store $store, int $count = 14): array
    {
        $day = now($store->timezone ?: config('app.timezone'))->startOfDay();

        return array_map(function (int $i) use ($store, $day) {
            $date = $day->copy()->addDays($i);

            return ['date' => $date->toDateString(), 'open' => $this->isOpen($store, $date)];
        }, range(0, $count - 1));
    }

    private function nextOpen(Store $store, Carbon $day): Carbon
    {
        for ($i = 0; $i < 60 && ! $this->isOpen($store, $day); $i++) {
            $day->addDay();
        }

        return $day;
    }
}
