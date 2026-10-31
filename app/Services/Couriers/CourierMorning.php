<?php

namespace App\Services\Couriers;

use App\Models\Courier;
use App\Models\Scopes\StoreScope;
use App\Models\Shipment;
use App\Notifications\CourierDayPlan;
use Illuminate\Support\Collection;
use Jibiat\Socle\Money;

/**
 * Chaque matin, chaque livreur connecté reçoit le résumé de sa journée : nombre de courses prévues aujourd'hui
 * (et celles en retard), argent à encaisser, communes.
 */
class CourierMorning
{
    /**
     * Courses du jour d'une liste de missions actives : prévues aujourd'hui ou avant, ou sans date.
     *
     * @param  Collection<int, Shipment>  $missions
     * @return Collection<int, Shipment>
     */
    public static function dueToday(Collection $missions): Collection
    {
        return $missions->filter(function (Shipment $s) {
            if ($s->status->value === 'returning') {
                return false;
            }
            $day = $s->status->value === 'rescheduled' && $s->rescheduled_to ? $s->rescheduled_to : $s->order?->scheduled_date;

            return $day === null || $day->lte(today());
        })->values();
    }

    /** @return int nombre de livreurs prévenus */
    public function send(): int
    {
        $sent = 0;
        Courier::withoutGlobalScope(StoreScope::class)->where('status', 'active')->whereNotNull('user_id')->with(['user', 'store'])
            ->chunkById(100, function ($couriers) use (&$sent) {
                foreach ($couriers as $courier) {
                    $missions = Shipment::forStore($courier->store_id)->where('courier_id', $courier->id)
                        ->whereIn('status', SharedCouriers::ACTIVE_MISSIONS)
                        ->with(['order' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'order.place.parent'])->get();
                    $today = self::dueToday($missions);
                    if ($today->isEmpty() || $courier->user === null) {
                        continue;
                    }
                    $late = $today->filter(fn (Shipment $s) => ($s->order?->scheduled_date?->lt(today()) ?? false))->count();
                    $cash = (int) $today->sum('cod_amount');
                    $communes = $today->map(fn (Shipment $s) => $s->order?->place
                        ? ($s->order->place->type === 'neighborhood' ? $s->order->place->parent?->name : $s->order->place->name) : null)
                        ->filter()->unique()->take(4)->implode(', ');
                    $count = $today->count();
                    $title = "Aujourd'hui : {$count} course".($count > 1 ? 's' : '').($late ? " dont {$late} en retard" : '').' · '.$courier->store->name;
                    $body = trim(($cash > 0 ? Money::format($cash).' à encaisser' : '').($communes !== '' ? ' · '.$communes : ''), ' ·');
                    $courier->user->notify(new CourierDayPlan($courier->store_id, $title, $body));
                    $sent++;
                }
            });

        return $sent;
    }
}
