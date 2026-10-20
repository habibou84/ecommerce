<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\Store;
use App\Notifications\OrderAlertNotice;
use App\Services\Accounts\TeamNotifier;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alertes sur les commandes qui attendent quelqu'un, reprises des alertes de Jibiat et adaptées au marchand :
 *  - sans livreur : commande confirmée, à livrer aujourd'hui (ou avant), confiée à personne ;
 *  - heure limite : à l'heure limite de la boutique, les commandes du jour pas encore parties chez un livreur ;
 *  - pas ramassée : course demandée ou acceptée, personne n'est venu chercher le colis ;
 *  - à confirmer : commande en attente de confirmation depuis longtemps, et rappels arrivés à l'heure ;
 *  - point du matin : un résumé de la journée à l'heure d'ouverture ;
 *  - livraisons qui traînent : colis encore en route à l'heure de fin des livraisons ;
 *  - problème non traité : échec de livraison ou décision demandée par le transporteur, sans suite.
 * Le délai d'une commande part de l'entrée dans son état (ou du début de son jour de livraison s'il est plus tard).
 * Chaque alerte part une seule fois, regroupée (« 3 commandes… »), dans la cloche et sur le téléphone de l'équipe ;
 * passé trois fois le délai, le propriétaire est prévenu. Rien la nuit : seulement pendant les heures d'alerte.
 */
class OrderAlerts
{
    public const DEFAULTS = [
        'hours' => ['from' => '07:00', 'to' => '21:00'],
        'unassigned' => ['on' => true, 'minutes' => 60],
        'cutoff' => ['on' => true],
        'pickup' => ['on' => true, 'minutes' => 120],
        'confirm' => ['on' => true, 'minutes' => 120],
        'callback' => ['on' => true],
        'morning' => ['on' => true],
        'stalled' => ['on' => true, 'time' => '19:00'],
        'unhandled' => ['on' => true, 'minutes' => 30],
    ];

    /** Une alerte non traitée après ce multiple du délai est remontée au propriétaire */
    public const ESCALATE = 3;

    private const TO_SHIP = [OrderStatus::Confirmed, OrderStatus::Processing];

    private const NOT_PICKED = [ShipmentStatus::Requested, ShipmentStatus::Accepted, ShipmentStatus::AwaitingPickup];

    private const ON_THE_WAY = [ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery];

    private const FINAL = [ShipmentStatus::Delivered, ShipmentStatus::Returned, ShipmentStatus::Cancelled, ShipmentStatus::Rejected, ShipmentStatus::Lost];

    public function __construct(private readonly TeamNotifier $team, private readonly DeliveryCalendar $calendar) {}

    /**
     * Réglages de la boutique, complétés par les valeurs par défaut.
     *
     * @return array<string, array<string, mixed>>
     */
    public function settings(Store $store): array
    {
        $saved = $store->alert_settings ?? [];

        return collect(self::DEFAULTS)->map(fn (array $defaults, string $key) => array_merge($defaults, array_intersect_key($saved[$key] ?? [], $defaults)))->all();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Files de la liste des commandes (sans délai) : même règle que les alertes

    /** Confirmées, à livrer aujourd'hui ou avant, sans livreur ni course en cours */
    public static function unassigned(Builder $orders): Builder
    {
        return $orders->whereIn('status', self::values(self::TO_SHIP))->where('delivery_method', 'delivery')
            ->where(fn (Builder $q) => $q->whereNull('scheduled_date')->orWhere('scheduled_date', '<=', today()->toDateString()))
            ->whereDoesntHave('shipments', fn (Builder $s) => $s->whereNotIn('status', self::values(self::FINAL)));
    }

    /** Course demandée ou acceptée, colis pas encore ramassé */
    public static function notPicked(Builder $orders): Builder
    {
        return $orders->whereHas('shipments', fn (Builder $s) => $s->whereIn('status', self::values(self::NOT_PICKED)));
    }

    /** Colis en route, à livrer aujourd'hui ou avant */
    public static function onTheWay(Builder $orders): Builder
    {
        return $orders->where('scheduled_date', '<=', today()->toDateString())
            ->whereHas('shipments', fn (Builder $s) => $s->whereIn('status', self::values(self::ON_THE_WAY)));
    }

    /** Échec de livraison ou décision demandée, pas encore traité */
    public static function unhandled(Builder $orders): Builder
    {
        return $orders->whereHas('shipments', fn (Builder $s) => $s->whereNotIn('status', self::values(self::FINAL))
            ->where(fn (Builder $q) => $q->where('status', ShipmentStatus::Failed->value)->orWhere('needs_decision', true)));
    }

    /**
     * Nombre de commandes dans chaque file (tableau de bord).
     *
     * @return array{unassigned: int, not_picked: int, unhandled: int}
     */
    public static function counts(): array
    {
        return [
            'unassigned' => self::unassigned(Order::query())->count(),
            'not_picked' => self::notPicked(Order::query())->count(),
            'unhandled' => self::unhandled(Order::query())->count(),
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Envoi (toutes les 5 minutes)

    /**
     * @return int nombre de notifications envoyées
     */
    public function run(): int
    {
        $sent = 0;
        $tenancy = app(Tenancy::class);
        $previous = $tenancy->store();
        Store::query()->with('owner')->each(function (Store $store) use (&$sent, $tenancy) {
            if (! $store->isOpen()) {
                return;
            }
            $tenancy->setStore($store);
            $sent += $this->forStore($store);
        });
        $tenancy->setStore($previous);

        return $sent;
    }

    public function forStore(Store $store, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now($store->timezone ?: config('app.timezone'));
        $settings = $this->settings($store);
        $from = $now->setTimeFromTimeString($settings['hours']['from']);
        if ($now->lt($from) || $now->gte($now->setTimeFromTimeString($settings['hours']['to']))) {
            return 0;
        }
        $sent = 0;
        $today = $now->toDateString();

        if ($settings['morning']['on']) {
            $sent += $this->morning($store, $now);
        }
        if ($settings['unassigned']['on']) {
            $orders = self::unassigned(Order::forStore($store->id))->with('shipments:id,order_id,status,status_changed_at')->get()
                ->mapWithKeys(fn (Order $o) => [$o->id => $this->unassignedSince($o, $from)]);
            $sent += $this->delays($store, 'unassigned', $orders, $settings['unassigned']['minutes'], $now,
                fn (int $n) => $n > 1 ? "{$n} commandes confirmées attendent un livreur" : 'Une commande confirmée attend un livreur',
                'Confiez-les à un livreur ou à une entreprise de livraison.', '/gestion/commandes?alert=unassigned');
        }
        if ($settings['cutoff']['on'] && $this->calendar->isOpen($store, Carbon::instance($now->toMutable()))) {
            $cutoff = $this->calendar->settings($store)['cutoff'];
            if ($now->gte($now->setTimeFromTimeString($cutoff))) {
                $count = self::unassigned(Order::forStore($store->id))->count();
                $pending = Order::forStore($store->id)->where('status', OrderStatus::PendingConfirmation->value)->where('scheduled_date', '<=', $today)->count();
                if ($count + $pending > 0 && $this->once($store, 'cutoff', "day:{$today}")) {
                    $detail = collect([$count ? "{$count} sans livreur" : null, $pending ? "{$pending} pas encore confirmée".($pending > 1 ? 's' : '') : null])->filter()->implode(' · ');
                    $sent += $this->send($store, Permission::ShipmentsManage, 'cutoff', 'Heure limite passée ('.str_replace(':', ' h ', $cutoff).')',
                        "Commandes du jour pas encore parties : {$detail}.", $count ? '/gestion/commandes?alert=unassigned' : '/gestion/commandes?status=pending_confirmation');
                }
            }
        }
        if ($settings['pickup']['on']) {
            $shipments = Shipment::forStore($store->id)->whereIn('status', self::values(self::NOT_PICKED))->with('order:id,scheduled_date')->get()
                ->mapWithKeys(fn (Shipment $s) => [$s->id => $this->latest($s->status_changed_at ?? $s->requested_at ?? $s->created_at, $this->dayStart($s->scheduled_date ?? $s->order?->scheduled_date, $from))]);
            $sent += $this->delays($store, 'pickup', $shipments, $settings['pickup']['minutes'], $now,
                fn (int $n) => $n > 1 ? "{$n} colis pas encore ramassés" : 'Un colis pas encore ramassé',
                'La course est demandée mais personne n\'est venu le chercher : appelez le livreur.', '/gestion/commandes?alert=not_picked', 'shipment');
        }
        if ($settings['confirm']['on']) {
            // Un rappel prévu a sa propre alerte, à l'heure choisie
            $orders = Order::forStore($store->id)->where('status', OrderStatus::PendingConfirmation->value)->whereNull('callback_at')->get(['id', 'created_at'])
                ->mapWithKeys(fn (Order $o) => [$o->id => $this->latest($o->created_at, $from)]);
            $sent += $this->delays($store, 'confirm', $orders, $settings['confirm']['minutes'], $now,
                fn (int $n) => $n > 1 ? "{$n} commandes attendent d'être confirmées" : 'Une commande attend d\'être confirmée',
                'Appelez les clients pour confirmer.', '/gestion/commandes?status=pending_confirmation', 'order', false, Permission::OrdersConfirm);
        }
        if ($settings['callback']['on']) {
            $due = Order::forStore($store->id)->where('status', OrderStatus::PendingConfirmation->value)->where('callback_at', '<=', now())->get(['id', 'customer_name', 'callback_at']);
            $new = $this->fresh($store, 'callback', $due->mapWithKeys(fn (Order $o) => ["order:{$o->id}:".$o->callback_at->timestamp => $o]));
            if ($new->isNotEmpty()) {
                $title = $new->count() > 1 ? "{$new->count()} clients à rappeler maintenant" : 'Rappeler '.($new->first()->customer_name ?: 'le client').' maintenant';
                $sent += $this->send($store, Permission::OrdersConfirm, 'callback', $title, 'Vous aviez prévu de le rappeler à cette heure-ci.',
                    $new->count() > 1 ? '/gestion/commandes?callback_due=1' : '/gestion/commandes/'.$new->first()->id);
            }
        }
        if ($settings['stalled']['on'] && $now->gte($now->setTimeFromTimeString($settings['stalled']['time']))) {
            $count = self::onTheWay(Order::forStore($store->id))->count();
            if ($count > 0 && $this->once($store, 'stalled', "day:{$today}")) {
                $sent += $this->send($store, Permission::ShipmentsManage, 'stalled', $count > 1 ? "{$count} colis encore en route" : 'Un colis encore en route',
                    'Ni livrés ni en échec à cette heure : vérifiez avec le livreur.', '/gestion/commandes?alert=on_the_way');
            }
        }
        if ($settings['unhandled']['on']) {
            $shipments = Shipment::forStore($store->id)->whereNotIn('status', self::values(self::FINAL))
                ->where(fn (Builder $q) => $q->where('status', ShipmentStatus::Failed->value)->orWhere('needs_decision', true))->get()
                ->mapWithKeys(fn (Shipment $s) => [$s->id => CarbonImmutable::parse($s->status_changed_at ?? $s->updated_at)]);
            $sent += $this->delays($store, 'unhandled', $shipments, $settings['unhandled']['minutes'], $now,
                fn (int $n) => $n > 1 ? "{$n} problèmes de livraison sans suite" : 'Un problème de livraison sans suite',
                'Échec ou décision demandée : reprogrammez, changez de livreur ou annulez.', '/gestion/commandes?alert=unhandled', 'shipment');
        }

        return $sent;
    }

    /**
     * Point du matin, une fois par jour à l'ouverture des alertes : seulement s'il y a quelque chose à faire.
     */
    private function morning(Store $store, CarbonImmutable $now): int
    {
        $today = $now->toDateString();
        $open = self::values([OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped]);
        $due = Order::forStore($store->id)->where('scheduled_date', $today)->whereIn('status', [...$open, OrderStatus::PendingConfirmation->value])->count();
        $unassigned = self::unassigned(Order::forStore($store->id))->count();
        $late = Order::forStore($store->id)->where('scheduled_date', '<', $today)->whereIn('status', $open)->count();
        $undated = Order::forStore($store->id)->whereNull('scheduled_date')->where('delivery_method', 'delivery')
            ->whereIn('status', [...$open, OrderStatus::PendingConfirmation->value])->count();
        if ($due + $unassigned + $late + $undated === 0 || ! $this->once($store, 'morning', "day:{$today}")) {
            return 0;
        }
        $parts = collect([
            $unassigned ? "{$unassigned} sans livreur" : null,
            $late ? "{$late} en retard" : null,
            $undated ? "{$undated} date à fixer" : null,
        ])->filter();

        return $this->send($store, Permission::OrdersView, 'morning', $due ? "Aujourd'hui : {$due} commande".($due > 1 ? 's' : '').' à livrer' : 'Le point du matin',
            $parts->isNotEmpty() ? ucfirst($parts->implode(' · ')).'.' : 'Tout est prêt pour les livraisons du jour.',
            $late ? '/gestion/commandes?status=late' : '/gestion/commandes?period=today');
    }

    /**
     * Alertes à délai : la première fois que le délai est dépassé (équipe), puis à trois fois le délai (propriétaire).
     *
     * @param  Collection<int, CarbonImmutable|null>  $since  début de l'attente par commande (ou expédition)
     */
    private function delays(Store $store, string $kind, Collection $since, int $minutes, CarbonImmutable $now, \Closure $title, string $body, string $url, string $type = 'order', bool $escalate = true, Permission $permission = Permission::ShipmentsManage): int
    {
        $waiting = $since->filter(fn (?CarbonImmutable $at) => $at !== null && $at->lte($now));
        $subject = fn (int $id) => "{$type}:{$id}:".$waiting[$id]->timestamp;
        $sent = 0;

        $late = $waiting->filter(fn (CarbonImmutable $at) => $at->diffInMinutes($now) >= $minutes)->keys();
        $first = $this->fresh($store, $kind, $late->mapWithKeys(fn (int $id) => [$subject($id) => $id]));
        if ($first->isNotEmpty()) {
            $sent += $this->send($store, $permission, $kind, $title($first->count()), $body.' En attente depuis plus de '.$this->duration($minutes).'.', $url);
        }
        if ($escalate && $store->owner !== null) {
            $old = $waiting->filter(fn (CarbonImmutable $at) => $at->diffInMinutes($now) >= self::ESCALATE * $minutes)->keys();
            $second = $this->fresh($store, $kind, $old->mapWithKeys(fn (int $id) => [$subject($id) => $id]), 2);
            if ($second->isNotEmpty()) {
                $store->owner->notify(new OrderAlertNotice($store->id, $kind.'_escalated', $title($second->count()).' depuis '.$this->duration(self::ESCALATE * $minutes),
                    'Personne ne s\'en est encore occupé.', $url));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Garde les sujets pas encore alertés et les marque comme alertés.
     *
     * @template T
     *
     * @param  Collection<string, T>  $items  par sujet
     * @return Collection<string, T>
     */
    private function fresh(Store $store, string $kind, Collection $items, int $level = 1): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }
        $done = DB::table('order_alerts')->where('store_id', $store->id)->where('kind', $kind)->where('level', $level)
            ->whereIn('subject', $items->keys())->pluck('subject')->flip();
        $new = $items->reject(fn ($item, string $subject) => $done->has($subject));
        DB::table('order_alerts')->insertOrIgnore($new->keys()->map(fn (string $subject) => [
            'store_id' => $store->id, 'kind' => $kind, 'subject' => $subject, 'level' => $level, 'created_at' => now(),
        ])->all());

        return $new;
    }

    private function once(Store $store, string $kind, string $subject): bool
    {
        return $this->fresh($store, $kind, collect([$subject => true]))->isNotEmpty();
    }

    private function send(Store $store, Permission $permission, string $kind, string $title, string $body, string $url): int
    {
        $this->team->notify($store, $permission->value, new OrderAlertNotice($store->id, $kind, $title, $body, $url));

        return 1;
    }

    /**
     * Sans livreur depuis : la confirmation, ou la fin de la dernière course (annulée, refusée…), et pas avant le
     * début de son jour de livraison.
     */
    private function unassignedSince(Order $order, CarbonImmutable $from): ?CarbonImmutable
    {
        $lastShipment = $order->shipments->max('status_changed_at');

        return $this->latest($order->confirmed_at ?? $order->created_at, $lastShipment, $this->dayStart($order->scheduled_date, $from));
    }

    /** Début des alertes le jour de livraison (aujourd'hui si la date est passée ou absente) */
    private function dayStart(mixed $date, CarbonImmutable $from): CarbonImmutable
    {
        if ($date === null) {
            return $from;
        }
        $day = CarbonImmutable::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date, $from->getTimezone());

        return $day->lte($from) ? $from->startOfDay() : $day->setTimeFrom($from);
    }

    private function latest(mixed ...$dates): CarbonImmutable
    {
        return collect($dates)->filter()->map(fn ($d) => CarbonImmutable::parse($d))->max();
    }

    private function duration(int $minutes): string
    {
        return $minutes < 60 ? "{$minutes} min" : intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '');
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(fn (\BackedEnum $c) => $c->value, $cases);
    }
}
