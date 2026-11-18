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
     * Courses du jour d'une liste de missions actives : prévues aujourd'hui (ou sans date), plus les colis à rapporter.
     * Une course dont la date est passée n'en fait pas partie : la boutique doit d'abord choisir une nouvelle date.
     *
     * @param  Collection<int, Shipment>  $missions
     * @return Collection<int, Shipment>
     */
    public static function dueToday(Collection $missions): Collection
    {
        return $missions->filter(fn (Shipment $s) => self::tab($s) !== null)->values();
    }

    /** Onglet de la course aujourd'hui dans l'application du livreur : pickup, deliver, return, ou aucun */
    public static function tab(Shipment $s): ?string
    {
        if ($s->status->value === 'returning') {
            return 'return';
        }
        if (! in_array($s->status->value, CourierDays::DATED, true) || ! (CourierDays::dueDay($s) ?? today())->isToday()) {
            return null;
        }

        return $s->status->value === 'awaiting_pickup' ? 'pickup' : 'deliver';
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
            $tabs = $today->countBy(fn (Shipment $s) => self::tab($s));
            $parts = collect(['pickup' => 'à ramasser', 'deliver' => 'à livrer', 'return' => 'à retourner'])
                ->filter(fn ($label, $tab) => ($tabs[$tab] ?? 0) > 0)->map(fn ($label, $tab) => $tabs[$tab].' '.$label)->implode(', ');
            $cash = (int) $today->filter(fn (Shipment $s) => self::tab($s) !== 'return')->sum('cod_amount');
            $communes = $today->map(fn (Shipment $s) => $s->order?->place
                ? ($s->order->place->type === 'neighborhood' ? $s->order->place->parent?->name : $s->order->place->name) : null)
                ->filter()->unique()->take(4)->implode(', ');
            $stores = $today->pluck('store_id')->unique()->count();
            $title = "Aujourd'hui : {$parts}"
                .' · '.($stores > 1 ? "{$stores} boutiques" : $fiches->firstWhere('store_id', $today->first()->store_id)?->store->name);
            $body = trim(($cash > 0 ? Money::format($cash).' à encaisser' : '').($communes !== '' ? ' · '.$communes : ''), ' ·');
            $user->notify(new CourierDayPlan($home->id, $title, $body));
            $sent++;
        }

        return $sent;
    }
}
