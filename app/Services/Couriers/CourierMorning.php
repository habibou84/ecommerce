<?php

namespace App\Services\Couriers;

use App\Models\Courier;
use App\Models\Scopes\StoreScope;
use App\Models\Shipment;
use App\Models\User;
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

    /** @return int nombre de livreurs prévenus (un seul message par personne, toutes ses boutiques réunies) */
    public function send(): int
    {
        $sent = 0;
        $userIds = Courier::withoutGlobalScope(StoreScope::class)->where('status', 'active')->whereNotNull('user_id')->distinct()->pluck('user_id');
        foreach ($userIds as $userId) {
            $home = SharedCouriers::homeOf((int) $userId);
            $user = User::find($userId);
            if ($home === null || $user === null) {
                continue;
            }
            $fiches = app(SharedCouriers::class)->ofPerson(Courier::withoutGlobalScope(StoreScope::class)->where('user_id', $userId)->where('store_id', $home->id)->firstOrFail());
            $missions = Shipment::withoutGlobalScope(StoreScope::class)->whereIn('courier_id', $fiches->pluck('id'))
                ->whereIn('status', SharedCouriers::ACTIVE_MISSIONS)
                ->with(['order' => fn ($q) => $q->withoutGlobalScope(StoreScope::class), 'order.place.parent'])->get();
            $today = self::dueToday($missions);
            if ($today->isEmpty()) {
                continue;
            }
            $late = $today->filter(fn (Shipment $s) => ($s->order?->scheduled_date?->lt(today()) ?? false))->count();
            $cash = (int) $today->sum('cod_amount');
            $communes = $today->map(fn (Shipment $s) => $s->order?->place
                ? ($s->order->place->type === 'neighborhood' ? $s->order->place->parent?->name : $s->order->place->name) : null)
                ->filter()->unique()->take(4)->implode(', ');
            $stores = $today->pluck('store_id')->unique()->count();
            $count = $today->count();
            $title = "Aujourd'hui : {$count} course".($count > 1 ? 's' : '').($late ? " dont {$late} en retard" : '')
                .' · '.($stores > 1 ? "{$stores} boutiques" : $fiches->firstWhere('store_id', $today->first()->store_id)?->store->name);
            $body = trim(($cash > 0 ? Money::format($cash).' à encaisser' : '').($communes !== '' ? ' · '.$communes : ''), ' ·');
            $user->notify(new CourierDayPlan($home->id, $title, $body));
            $sent++;
        }

        return $sent;
    }
}
