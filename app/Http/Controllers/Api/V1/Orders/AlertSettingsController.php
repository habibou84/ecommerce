<?php

namespace App\Http\Controllers\Api\V1\Orders;

use App\Http\Controllers\Controller;
use App\Services\Orders\DeliveryCalendar;
use App\Services\Orders\OrderAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Réglages des alertes sur les commandes qui attendent : chaque alerte activée ou non, avec son délai, et les heures
 * pendant lesquelles elles partent.
 */
class AlertSettingsController extends Controller
{
    public function show(OrderAlerts $alerts, DeliveryCalendar $calendar): JsonResponse
    {
        $store = $this->currentStore();

        return response()->json(['data' => $alerts->settings($store) + ['cutoff_time' => $calendar->settings($store)['cutoff']]]);
    }

    public function update(Request $request, OrderAlerts $alerts, DeliveryCalendar $calendar): JsonResponse
    {
        $rules = [
            'hours.from' => ['required', 'date_format:H:i'],
            'hours.to' => ['required', 'date_format:H:i', 'after:hours.from'],
            'stalled.time' => ['required', 'date_format:H:i'],
        ];
        foreach (array_keys(OrderAlerts::DEFAULTS) as $key) {
            if ($key !== 'hours') {
                $rules["{$key}.on"] = ['required', 'boolean'];
            }
        }
        foreach (['unassigned', 'pickup', 'confirm', 'unhandled'] as $key) {
            $rules["{$key}.minutes"] = ['required', 'integer', 'between:10,1440'];
        }
        $data = $request->validate($rules, [], [
            'hours.from' => 'début des alertes', 'hours.to' => 'fin des alertes', 'stalled.time' => 'heure de fin des livraisons',
            'unassigned.minutes' => 'délai « sans livreur »', 'pickup.minutes' => 'délai « pas ramassé »',
            'confirm.minutes' => 'délai « à confirmer »', 'unhandled.minutes' => 'délai « problème sans suite »',
        ]);

        $this->currentStore()->forceFill(['alert_settings' => $data])->save();

        return $this->show($alerts, $calendar);
    }
}
