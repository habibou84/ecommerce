<?php

namespace App\Services\Orders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Place;
use App\Models\Shipment;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Jibiat\Socle\PhoneNumber;

/**
 * Liste des commandes de la gestion : recherche, période (date de commande ou de livraison), qui livre, commune,
 * paiement, canal, tri ; résumé de la liste et lignes enrichies (articles, qui livre, date de livraison).
 * Partagée par l'écran et l'export.
 */
class OrderListing
{
    /** Statuts où la commande attend encore d'être livrée : une date de livraison passée la met « en retard » */
    public const OPEN = [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped];

    /** Statuts où une commande à livrer sans date doit encore en recevoir une (« Date à fixer ») */
    public const NEEDS_DATE = ['pending_confirmation', 'confirmed', 'processing', 'shipped'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([...OrderStatus::values(), 'to_ship', 'at_courier', 'late'])],
            'search' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', Rule::enum(OrderSource::class)],
            'customer_id' => ['nullable', 'integer'],
            'callback_due' => ['nullable', 'boolean'],
            'period' => ['nullable', Rule::in(['today', 'yesterday', 'tomorrow', '7d', 'month', 'custom'])],
            'date_field' => ['nullable', Rule::in(['created', 'scheduled'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'deliverer' => ['nullable', 'string', 'regex:/^(none|courier:\d+|carrier:\d+)$/'],
            'commune_id' => ['nullable', 'integer'],
            'payment' => ['nullable', Rule::in(['to_collect', 'paid'])],
            'alert' => ['nullable', Rule::in(['unassigned', 'not_picked', 'on_the_way', 'unhandled', 'to_bring_back'])],
            'sort' => ['nullable', Rule::in(['recent', 'oldest', 'scheduled', 'amount'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Commandes qui correspondent aux filtres, sans le filtre de statut (pour compter chaque onglet).
     */
    public function filtered(Request $request): Builder
    {
        $search = trim((string) $request->query('search'));

        return Order::query()
            ->when($search !== '', function (Builder $q) use ($search) {
                $digits = preg_replace('/\D/', '', $search);
                $q->where(function (Builder $q) use ($search, $digits) {
                    $q->where('customer_name', 'ilike', "%{$search}%");
                    if ($digits !== '' && strlen($digits) <= 9) {
                        $q->orWhere('number', (int) $digits);
                    }
                    if (strlen($digits) >= 4) {
                        $q->orWhere('customer_phone', 'like', '%'.(PhoneNumber::normalize($search) ? ltrim((string) PhoneNumber::normalize($search), '+') : $digits).'%');
                    }
                });
            })
            ->when($request->query('source'), fn (Builder $q, $source) => $q->where('source', $source))
            ->when($request->query('customer_id'), fn (Builder $q, $id) => $q->where('customer_id', $id))
            ->when($this->period($request), function (Builder $q, array $range) use ($request) {
                $request->query('date_field', 'scheduled') === 'scheduled'
                    ? $q->whereBetween('scheduled_date', [$range[0]->toDateString(), $range[1]->toDateString()])
                    : $q->whereBetween('created_at', $range);
            })
            ->when($request->query('deliverer'), fn (Builder $q, string $who) => $this->deliverer($q, $who))
            ->when($request->query('alert'), fn (Builder $q, string $alert) => match ($alert) {
                'unassigned' => OrderAlerts::unassigned($q),
                'not_picked' => OrderAlerts::notPicked($q),
                'on_the_way' => OrderAlerts::onTheWay($q),
                'to_bring_back' => OrderAlerts::toBringBack($q),
                default => OrderAlerts::unhandled($q),
            })
            ->when($request->query('commune_id'), function (Builder $q, $commune) {
                $ids = Place::where('parent_id', $commune)->pluck('id')->push((int) $commune);
                $q->whereIn('shipping_place_id', $ids->merge(Place::whereIn('parent_id', $ids)->pluck('id'))->unique());
            })
            ->when($request->query('payment'), fn (Builder $q, $payment) => $payment === 'paid'
                ? $q->where('payment_status', 'paid')
                : $q->whereIn('payment_status', ['unpaid', 'partially_paid'])->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value]));
    }

    /**
     * Avec le filtre de statut (« à faire livrer », « en retard » compris) et le tri.
     */
    public function query(Request $request): Builder
    {
        $query = $this->withStatus($this->filtered($request), $request->query('status'))
            ->when($request->boolean('callback_due'), fn (Builder $q) => $q->where('callback_at', '<=', now()));

        return match ($request->query('sort', 'scheduled')) {
            'recent' => $query->latest('id'),
            'oldest' => $query->oldest('id'),
            'amount' => $query->orderByDesc('total')->latest('id'),
            default => $this->byDeliveryDay($query),
        };
    }

    /**
     * Tri par jour de livraison, le plus lointain en haut : d'abord les commandes en cours dont la date est à fixer,
     * puis chaque jour de livraison (une commande sans date, retrait ou ancienne commande terminée, compte au jour
     * où elle a été passée).
     */
    private function byDeliveryDay(Builder $query): Builder
    {
        $open = "'".implode("','", self::NEEDS_DATE)."'";

        return $query
            ->orderByRaw("(scheduled_date IS NULL AND delivery_method = 'delivery' AND status IN ({$open})) DESC")
            ->orderByRaw('COALESCE(scheduled_date, CAST(created_at AS DATE)) DESC')
            ->latest('id');
    }

    /**
     * Jour sous lequel la liste range chaque commande (« AAAA-MM-JJ », ou rien pour « date à fixer ») : jour de
     * livraison, ou jour de la commande quand on trie par date de commande. Même règle que le tri.
     */
    public function dayKey(Request $request): string
    {
        $tz = app(Tenancy::class)->store()?->timezone;
        $tz = in_array($tz, timezone_identifiers_list(), true) ? $tz : config('app.timezone');
        $created = "CAST((created_at AT TIME ZONE 'UTC') AT TIME ZONE '{$tz}' AS DATE)";
        if (in_array($request->query('sort'), ['recent', 'oldest'], true)) {
            return "CAST({$created} AS TEXT)";
        }
        $open = "'".implode("','", self::NEEDS_DATE)."'";

        return "CASE WHEN scheduled_date IS NULL AND delivery_method = 'delivery' AND status IN ({$open}) THEN NULL ELSE CAST(COALESCE(scheduled_date, {$created}) AS TEXT) END";
    }

    /**
     * Nombre et montant de chaque jour, sur toute la liste filtrée (pas seulement la page) : un jour coupé entre
     * deux pages garde son vrai total.
     *
     * @param  list<string|null>  $days
     * @return array<string, array{count: int, amount: int}> clé « none » pour « date à fixer »
     */
    public function dayTotals(Request $request, array $days): array
    {
        if ($days === []) {
            return [];
        }
        $key = $this->dayKey($request);
        $dates = array_values(array_filter($days));

        return $this->query($request)->reorder()->toBase()
            ->where(function ($q) use ($key, $dates, $days) {
                if ($dates !== []) {
                    $q->whereRaw("({$key}) BETWEEN ? AND ?", [min($dates), max($dates)]);
                }
                if (in_array(null, $days, true)) {
                    $q->orWhereRaw("({$key}) IS NULL");
                }
            })
            ->selectRaw("({$key}) AS day, COUNT(*) AS n, COALESCE(SUM(CASE WHEN status IN ('cancelled', 'returned') THEN 0 ELSE total END), 0) AS amount")
            ->groupByRaw($key)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->day ?? 'none' => ['count' => (int) $row->n, 'amount' => (int) $row->amount]])
            ->all();
    }

    public function withStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            null, '' => $query,
            // À faire livrer : confirmées sans course en cours ; Chez le livreur : course demandée, pas encore partie
            'to_ship' => $query->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Processing->value])->whereDoesntHave('shipments', $this->activeShipment(...)),
            'at_courier' => $query->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Processing->value])->whereHas('shipments', $this->activeShipment(...)),
            'late' => $this->late($query),
            default => $query->where('status', $status),
        };
    }

    private function activeShipment(Builder $q): void
    {
        $q->whereNotIn('status', array_map(fn (ShipmentStatus $s) => $s->value, array_filter(ShipmentStatus::cases(), fn (ShipmentStatus $s) => $s->isFinal())));
    }

    public function late(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn ($s) => $s->value, self::OPEN))->where('scheduled_date', '<', today()->toDateString());
    }

    /**
     * Nombre de commandes par onglet, avec les autres filtres.
     *
     * @return array<string, int>
     */
    public function counts(Request $request): array
    {
        $byStatus = $this->filtered($request)->toBase()->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status');

        return collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($byStatus[$s->value] ?? 0)])
            ->put('to_ship', $this->withStatus($this->filtered($request), 'to_ship')->count())
            ->put('at_courier', $this->withStatus($this->filtered($request), 'at_courier')->count())
            ->put('late', $this->late($this->filtered($request))->count())
            ->all();
    }

    /**
     * Résumé de la liste affichée : nombre, montant, reste à encaisser ; et commandes à livrer aujourd'hui.
     *
     * @return array{count: int, amount: int, to_collect: int, due_today: int}
     */
    public function summary(Request $request, ?int $total = null): array
    {
        $open = fn (Builder $q) => $q->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Returned->value]);
        $row = $open($this->query($request)->reorder())->toBase()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) AS amount, COALESCE(SUM(GREATEST(total - amount_paid + amount_refunded, 0)), 0) AS due')->first();

        return [
            'count' => $total ?? $this->query($request)->count(),
            'amount' => (int) $row->amount,
            'to_collect' => (int) $row->due,
            'due_today' => Order::where('scheduled_date', today()->toDateString())->whereIn('status', array_map(fn ($s) => $s->value, self::OPEN))->count(),
        ];
    }

    /**
     * Ligne de la liste : la commande, plus ses articles en bref, qui livre et la date de livraison.
     *
     * @return array<string, mixed>
     */
    public function extras(Order $order): array
    {
        $shipment = $order->shipments->first(fn (Shipment $s) => ! $s->status->isFinal()) ?? $order->shipments->last();
        $deliverer = $shipment?->courier?->name ?? $shipment?->connection?->name ?? $order->plannedCourier?->name ?? $order->plannedCarrier?->name;
        $titles = $order->items->pluck('title')->unique()->values();

        return [
            'scheduled_date' => $order->scheduled_date?->toDateString(),
            'needs_date' => $order->scheduled_date === null && $order->delivery_method === 'delivery' && in_array($order->status->value, self::NEEDS_DATE, true),
            'is_late' => in_array($order->status, self::OPEN, true) && $order->scheduled_date?->lt(today()),
            'items_label' => $order->items->sum('quantity').' article'.($order->items->sum('quantity') > 1 ? 's' : '').($titles->isNotEmpty() ? ' · '.$titles->take(2)->implode(', ').($titles->count() > 2 ? '…' : '') : ''),
            'deliverer' => $deliverer,
            'deliverer_planned' => $shipment === null && $deliverer !== null,
            // « Modifier » le livreur depuis la liste : choix pas encore envoyé, ou course de mon livreur pas encore partie
            'deliverer_key' => $this->delivererKey($order, $shipment),
            'shipment_id' => $shipment && ! $shipment->status->isFinal() ? $shipment->id : null,
            'deliverer_editable' => $deliverer !== null && $order->delivery_method === 'delivery' && (
                ($shipment === null && in_array($order->status, [OrderStatus::PendingConfirmation, OrderStatus::Confirmed, OrderStatus::Processing], true))
                || ($shipment !== null && $shipment->isOwnCourier() && ! $shipment->status->isFinal()
                    && ! in_array($shipment->status, [ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Returning], true))),
            // Expédition payée d'avance pas encore réglée : le lien de paiement d'abord, pas de course possible
            'prepay_due' => Expeditions::blockingDue($order),
            'whatsapp_url' => $order->customer_phone ? 'https://wa.me/'.ltrim($order->customer_phone, '+') : null,
        ];
    }

    private function delivererKey(Order $order, ?Shipment $shipment): ?string
    {
        if ($shipment) {
            return $shipment->courier_id ? 'courier:'.$shipment->courier_id : ($shipment->carrier_connection_id ? 'carrier:'.$shipment->carrier_connection_id : null);
        }

        return $order->planned_courier_id ? 'courier:'.$order->planned_courier_id : ($order->planned_carrier_connection_id ? 'carrier:'.$order->planned_carrier_connection_id : null);
    }

    /**
     * Relations utiles aux lignes de la liste.
     *
     * @return list<string|array<string, \Closure>>
     */
    public static function relations(): array
    {
        return ['store', 'items:id,order_id,title,quantity', 'shipments.courier:id,name', 'shipments.connection:id,name', 'plannedCourier:id,name', 'plannedCarrier:id,name', 'expeditionCity:id,name'];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function period(Request $request): ?array
    {
        return match ($request->query('period')) {
            'today' => [today(), today()->endOfDay()],
            'yesterday' => [today()->subDay(), today()->subDay()->endOfDay()],
            'tomorrow' => [today()->addDay(), today()->addDay()->endOfDay()],
            '7d' => [today()->subDays(6), today()->endOfDay()],
            'month' => [today()->startOfMonth(), today()->endOfMonth()->endOfDay()],
            'custom' => $request->query('from') ? [Carbon::parse($request->query('from'))->startOfDay(), Carbon::parse($request->query('to') ?: $request->query('from'))->endOfDay()] : null,
            default => null,
        };
    }

    private function deliverer(Builder $query, string $who): void
    {
        if ($who === 'none') {
            $query->whereNull('planned_courier_id')->whereNull('planned_carrier_connection_id')->whereDoesntHave('shipments');

            return;
        }
        [$type, $id] = explode(':', $who);
        $column = $type === 'courier' ? 'courier_id' : 'carrier_connection_id';
        $planned = $type === 'courier' ? 'planned_courier_id' : 'planned_carrier_connection_id';
        $query->where(fn (Builder $q) => $q->whereHas('shipments', fn (Builder $s) => $s->where($column, (int) $id))
            ->orWhere(fn (Builder $q) => $q->where($planned, (int) $id)->whereDoesntHave('shipments')));
    }
}
