<?php

namespace App\Services\Shipping;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\Permission;
use App\Enums\ShipmentStatus;
use App\Enums\StockLocationType;
use App\Exceptions\BusinessRuleException;
use App\Models\CarrierConnection;
use App\Models\CarrierProductMapping;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\StockLocation;
use App\Models\User;
use App\Notifications\ShipmentAlert;
use App\Services\Accounts\TeamNotifier;
use App\Services\Couriers\CourierMissions;
use App\Services\Orders\OrderDesk;
use App\Services\Shipping\Drivers\RemoteShipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use Jibiat\Socle\PhoneNumber;
use Throwable;

/**
 * Expéditions : demande de course au transporteur, annulation, et répercussion de ses statuts sur la commande
 * (sortie de stock au ramassage, encaissement à la livraison, remise en stock au retour).
 */
class ShipmentDesk
{
    public function __construct(
        private readonly CarrierConnections $connections,
        private readonly OrderDesk $orders,
        private readonly TeamNotifier $notifier,
        private readonly CourierMissions $couriers,
    ) {}

    /**
     * Devis de chaque transporteur connecté pour une commande (null : pas de devis, zone non couverte).
     *
     * @return list<array{connection_id: int, name: string, driver: string, fee: ?int, zone: ?string, error: ?string}>
     */
    public function quotes(Order $order): array
    {
        $quotes = [];
        foreach (CarrierConnection::forStore($order->store_id)->where('status', 'active')->orderBy('name')->get() as $connection) {
            $zone = $this->connections->zoneForPlace($connection, $order->shipping_place_id);
            $quote = ['connection_id' => $connection->id, 'name' => $connection->name, 'driver' => $connection->driver, 'fee' => null, 'zone' => $zone?->label(), 'error' => null];

            if ($connection->isApi()) {
                if ($zone === null) {
                    $quote['error'] = 'Quartier non couvert (ou sans correspondance de zone).';
                } else {
                    try {
                        $quote['fee'] = $this->connections->driver($connection)->quote($connection, [
                            'zone_id' => $zone->remote_id,
                            'is_fragile' => $order->items->contains(fn ($i) => $i->product?->is_fragile),
                            'weight_kg' => $this->weightKg($order),
                        ]);
                    } catch (CarrierException $e) {
                        $quote['error'] = $e->getMessage();
                    }
                }
            }
            $quotes[] = $quote;
        }

        return $quotes;
    }

    /**
     * @param  array{origin_location_id?: ?int, note?: ?string, fee_payer?: ?string}  $options
     */
    public function create(Order $order, CarrierConnection $connection, array $options, ?User $actor): Shipment
    {
        $this->ensureShippable($order);
        if (! $connection->isActive() || $connection->store_id !== $order->store_id) {
            throw new BusinessRuleException('Cette entreprise de livraison n\'est pas connectée.', 'carrier_connection_id');
        }

        $origin = $this->origin($order, $connection, $options['origin_location_id'] ?? null);
        $feePayer = in_array($options['fee_payer'] ?? null, ['store', 'customer'], true) ? $options['fee_payer'] : $connection->setting('fee_payer', 'store');
        $due = $order->amountDue();

        $shipment = Shipment::create([
            'store_id' => $order->store_id,
            'order_id' => $order->id,
            'sequence' => (int) $order->shipments->max('sequence') + 1,
            'mode' => $connection->isApi() ? 'carrier' : 'manual_carrier',
            'status' => ShipmentStatus::Requested,
            'origin_location_id' => $origin?->id,
            'carrier_connection_id' => $connection->id,
            'idempotency_key' => (string) Str::uuid(),
            'cod_amount' => $due,
            'fee_payer' => $feePayer,
            'weight_g' => $this->weightG($order),
            'is_fragile' => $order->items->contains(fn ($i) => $i->product?->is_fragile),
            'note' => $options['note'] ?? null,
            'requested_at' => now(),
            'created_by' => $actor?->id,
        ]);
        $shipment->setRelation('order', $order)->setRelation('connection', $connection);

        try {
            $remote = $this->connections->driver($connection)->create($connection, $shipment, $this->payload($shipment, $order, $connection, $origin, $feePayer, $due));
        } catch (Throwable $e) {
            $shipment->delete();

            throw $e instanceof BusinessRuleException ? $e : CarrierException::unreachable($connection->name);
        }

        DB::transaction(function () use ($shipment, $order, $remote, $actor, $connection) {
            $shipment->forceFill([
                'carrier_tracking_code' => $remote->trackingCode !== '' ? $remote->trackingCode : null,
                'carrier_tracking_url' => $remote->trackingUrl,
                'carrier_status' => $remote->rawStatus,
                'carrier_fee' => $remote->fee,
                'delivery_code' => $remote->deliveryCode,
            ])->save();
            $this->event($shipment, ShipmentStatus::Requested, $remote->rawStatus, 'user', $connection->isApi() ? 'Course créée chez '.$connection->name : 'À demander à '.$connection->name, $actor);

            if ($order->status === OrderStatus::Confirmed) {
                $this->orders->transition($order, OrderStatus::Processing, $actor, ['note' => 'Livraison '.$shipment->reference()]);
            }
            $this->orders->log($order, 'shipment_created', ['shipment_id' => $shipment->id, 'carrier' => $connection->name, 'tracking_code' => $shipment->carrier_tracking_code], $actor);
        });

        // Le statut renvoyé à la création (acceptée d'office, par exemple) est appliqué aussitôt
        if ($remote->status !== null && $remote->status !== ShipmentStatus::Requested) {
            $this->apply($shipment, $remote, 'carrier_sync');
        }

        return $shipment->fresh(['order', 'connection']);
    }

    /**
     * Livraison par un livreur de la boutique : la mission apparaît dans son application.
     *
     * @param  array{origin_location_id?: ?int, note?: ?string}  $options
     */
    public function assignCourier(Order $order, Courier $courier, array $options, ?User $actor): Shipment
    {
        $this->ensureShippable($order);
        if (! $courier->isActive() || $courier->store_id !== $order->store_id) {
            throw new BusinessRuleException('Ce livreur n\'est pas actif (invitation pas encore acceptée ?).', 'courier_id');
        }

        $locationId = $options['origin_location_id'] ?? $order->location_id;
        $shipment = DB::transaction(function () use ($order, $courier, $options, $actor, $locationId) {
            $shipment = Shipment::create([
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'sequence' => (int) $order->shipments->max('sequence') + 1,
                'mode' => 'own_courier',
                'status' => ShipmentStatus::AwaitingPickup,
                'origin_location_id' => $locationId ? StockLocation::forStore($order->store_id)->find($locationId)?->id : null,
                'courier_id' => $courier->id,
                'idempotency_key' => (string) Str::uuid(),
                'cod_amount' => $order->amountDue(),
                'fee_payer' => 'customer',
                'weight_g' => $this->weightG($order),
                'is_fragile' => $order->items->contains(fn ($i) => $i->product?->is_fragile),
                // Code remis au client (page de suivi), demandé par le livreur à la remise du colis
                'delivery_code' => $order->store->require_delivery_code ? (string) random_int(1000, 9999) : null,
                'note' => $options['note'] ?? null,
                'requested_at' => now(),
                'created_by' => $actor?->id,
            ]);
            $shipment->setRelation('order', $order);

            $this->couriers->assign($shipment, $courier, $actor);
            $this->event($shipment, ShipmentStatus::AwaitingPickup, null, 'user', 'Confiée à '.$courier->name, $actor);
            if ($order->status === OrderStatus::Confirmed) {
                $this->orders->transition($order, OrderStatus::Processing, $actor, ['note' => 'Livraison '.$shipment->reference()]);
            }
            $this->orders->log($order, 'shipment_created', ['shipment_id' => $shipment->id, 'carrier' => $courier->name], $actor);

            return $shipment;
        });

        return $shipment->fresh(['order', 'courier']);
    }

    /**
     * Autre livreur pour une mission pas encore livrée.
     */
    public function reassign(Shipment $shipment, Courier $courier, ?User $actor): Shipment
    {
        if (! $shipment->isOwnCourier() || $shipment->status->isFinal() || in_array($shipment->status, [ShipmentStatus::Returning], true)) {
            throw new BusinessRuleException('Cette livraison ne peut plus changer de livreur.');
        }
        if (! $courier->isActive() || $courier->store_id !== $shipment->store_id) {
            throw new BusinessRuleException('Ce livreur n\'est pas actif.', 'courier_id');
        }

        DB::transaction(function () use ($shipment, $courier, $actor) {
            $this->couriers->assign($shipment, $courier, $actor);
            $this->event($shipment, $shipment->status, null, 'user', 'Confiée à '.$courier->name, $actor);
        });

        return $shipment->fresh(['courier']);
    }

    private function ensureShippable(Order $order): void
    {
        $order->loadMissing(['items.product', 'store', 'shipments']);

        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            throw new BusinessRuleException('Seule une commande confirmée peut être confiée à la livraison.');
        }
        if ($order->delivery_method !== 'delivery') {
            throw new BusinessRuleException('Cette commande est à retirer en boutique.');
        }
        if ($order->shipments->contains(fn (Shipment $s) => ! in_array($s->status, [ShipmentStatus::Cancelled, ShipmentStatus::Rejected], true))) {
            throw new BusinessRuleException('Cette commande a déjà une livraison en cours.');
        }
    }

    /**
     * Message WhatsApp à envoyer à un transporteur sans API.
     */
    public function requestMessage(Shipment $shipment): string
    {
        $order = $shipment->order->loadMissing('items');
        $lines = [
            "Bonjour, nouvelle course pour {$order->store->name} ({$shipment->reference()}) :",
            'Client : '.trim(($order->shipping_name ?? $order->customer_name).' '.PhoneNumber::display($order->shipping_phone ?? $order->customer_phone)),
            'Livraison : '.collect([$order->shipping_place_label, $order->shipping_address, $order->shipping_landmark])->filter()->implode(', '),
            'Articles : '.$order->items->map(fn ($i) => $i->quantity.' × '.$i->label())->implode(', '),
            'À encaisser : '.Money::format($shipment->cod_amount),
        ];
        if ($shipment->origin) {
            $lines[] = 'Ramassage : '.collect([$shipment->origin->name, $shipment->origin->address])->filter()->implode(', ');
        }

        return implode("\n", $lines);
    }

    public function cancel(Shipment $shipment, ?string $reason, ?User $actor): Shipment
    {
        if (! $shipment->status->isCancellable()) {
            throw new BusinessRuleException('Le colis est déjà parti : la livraison ne peut plus être annulée.');
        }

        $connection = $shipment->connection;
        $remote = $connection
            ? $this->connections->driver($connection)->cancel($connection, $shipment, $reason)
            : new RemoteShipment(trackingCode: '', status: ShipmentStatus::Cancelled, rawStatus: 'cancelled');
        $this->apply($shipment, $remote, 'user', $reason, $actor);

        return $shipment->fresh();
    }

    /**
     * Statut saisi à la main (transporteur sans API, ou livreur de la boutique : réception d'un colis retourné…).
     */
    public function updateManually(Shipment $shipment, ShipmentStatus $status, ?string $note, ?int $collected, ?User $actor): Shipment
    {
        if ($shipment->connection?->isApi()) {
            throw new BusinessRuleException('Le statut de cette livraison est donné par l\'entreprise de livraison.');
        }
        if (! in_array($status, ShipmentStatus::manual(), true) || $shipment->status->isFinal()) {
            throw new BusinessRuleException('Statut impossible pour cette livraison.', 'status');
        }

        $this->apply($shipment, new RemoteShipment(
            trackingCode: (string) $shipment->carrier_tracking_code,
            status: $status,
            rawStatus: $status->value,
            collected: $status === ShipmentStatus::Delivered ? ($collected ?? $shipment->cod_amount) : null,
            incident: $status === ShipmentStatus::Failed ? $note : null,
        ), 'user', $note, $actor);

        return $shipment->fresh();
    }

    public function refresh(Shipment $shipment): Shipment
    {
        $connection = $shipment->connection ?? throw new BusinessRuleException('Cette livraison est suivie par votre livreur.');
        $this->apply($shipment, $this->connections->driver($connection)->fetch($connection, $shipment), 'carrier_sync');

        return $shipment->fresh();
    }

    /**
     * Applique l'état donné par le transporteur (webhook, synchronisation, saisie). Sans effet si rien ne change ;
     * un statut plus ancien que le dernier connu (webhook en retard) ne revient pas en arrière.
     */
    public function apply(Shipment $shipment, RemoteShipment $remote, string $source, ?string $note = null, ?User $actor = null): void
    {
        DB::transaction(function () use ($shipment, $remote, $source, $note, $actor) {
            $shipment = Shipment::forStore($shipment->store_id)->lockForUpdate()->with(['order', 'connection'])->findOrFail($shipment->id);
            $occurredAt = $remote->occurredAt ? Carbon::parse($remote->occurredAt) : now();
            $lastEventAt = ShipmentEvent::where('shipment_id', $shipment->id)->max('occurred_at');
            $stale = $source !== 'user' && $lastEventAt !== null && $occurredAt->lt(Carbon::parse($lastEventAt));

            $shipment->forceFill(array_filter([
                'carrier_status' => $stale ? null : $remote->rawStatus,
                'carrier_tracking_url' => $remote->trackingUrl,
                'carrier_fee' => $remote->fee,
                'collected_amount' => $remote->collected,
                'delivery_code' => $remote->deliveryCode,
                'proof_photo_url' => $remote->proofPhotoUrl,
                'proof_photo_path' => $remote->proofPhotoPath,
                'attempts_count' => $remote->attempts ?: null,
                'failure_reason' => $remote->incident,
                'rescheduled_to' => $remote->rescheduledTo,
            ], fn ($v) => $v !== null))->forceFill(['last_synced_at' => $source === 'user' ? $shipment->last_synced_at : now()]);

            $to = $remote->status;
            if ($to === null || $to === $shipment->status || $stale) {
                $shipment->save();

                return;
            }

            $shipment->status = $to;
            match ($to) {
                ShipmentStatus::InTransit => $shipment->picked_up_at ??= $occurredAt,
                ShipmentStatus::Delivered => $shipment->delivered_at = $occurredAt,
                ShipmentStatus::Returned => $shipment->returned_at = $occurredAt,
                ShipmentStatus::Cancelled => $shipment->cancelled_at = $occurredAt,
                default => null,
            };
            $shipment->picked_up_at ??= $to->hasLeft() ? $occurredAt : null;
            $shipment->save();

            $this->event($shipment, $to, $remote->rawStatus, $source, $note ?? $remote->incident, $actor, $occurredAt);
            $this->updateOrder($shipment, $to, $actor, $source, $remote);
        });
    }

    private function updateOrder(Shipment $shipment, ShipmentStatus $to, ?User $actor, string $source, RemoteShipment $remote): void
    {
        $order = $shipment->order->fresh();
        $label = 'Livraison '.$shipment->reference();

        // Le colis est parti : sortie de stock
        if ($to->hasLeft() && $order->status->holdsStock()) {
            $order = $this->orders->transition($order, OrderStatus::Shipped, $actor, ['note' => $label.' : ramassée']);
        }

        match ($to) {
            ShipmentStatus::Delivered => $this->delivered($shipment, $order, $actor, $remote),
            ShipmentStatus::Returned => $order->status->canTransitionTo(OrderStatus::Returned)
                ? $this->orders->transition($order, OrderStatus::Returned, $actor, ['note' => $label.' : colis retourné', 'restock' => true]) : null,
            // Course refusée ou annulée avant le ramassage : la commande revient à « Confirmée »
            ShipmentStatus::Cancelled, ShipmentStatus::Rejected => $order->status === OrderStatus::Processing
                ? $this->orders->transition($order, OrderStatus::Confirmed, $actor, ['note' => $label.' : '.$to->label()]) : null,
            default => null,
        };

        $alert = match ($to) {
            ShipmentStatus::Rejected => 'Course refusée',
            ShipmentStatus::Failed => 'Échec de livraison',
            ShipmentStatus::Rescheduled => 'Livraison reportée',
            ShipmentStatus::Lost => 'Colis perdu',
            ShipmentStatus::Returned => 'Colis retourné',
            default => null,
        };
        // Alerte quand l'information vient du terrain (transporteur ou livreur), pas d'une saisie dans la gestion
        if ($alert && ($actor === null || $source === 'courier')) {
            $this->notifier->notify($shipment->store, Permission::OrdersView->value, new ShipmentAlert($shipment, $alert, $shipment->failure_reason));
        }

        if ($shipment->isOwnCourier()) {
            $this->couriers->afterStatus($shipment, $to);
        }
    }

    /**
     * Livrée : la commande est terminée et l'argent encaissé est noté : par le transporteur (il le reversera) ou par
     * le livreur de la boutique (il le détient jusqu'à son versement à la caisse).
     */
    private function delivered(Shipment $shipment, Order $order, ?User $actor, RemoteShipment $remote): void
    {
        if ($order->status->canTransitionTo(OrderStatus::Completed)) {
            $order = $this->orders->transition($order, OrderStatus::Completed, $actor, ['note' => 'Livraison '.$shipment->reference().' terminée']);
        }

        $collected = min($shipment->collected_amount ?? $shipment->cod_amount, $order->amountDue());
        if ($collected > 0 && $shipment->isOwnCourier()) {
            $method = PaymentMethod::tryFrom((string) $remote->paymentMethod) ?? PaymentMethod::Cash;
            $payment = $this->orders->recordPayment($order, [
                'amount' => $collected,
                'method' => $method,
                'kind' => $method === PaymentMethod::Cash ? PaymentKind::CodCash : PaymentKind::CodMobile,
                'reference' => $shipment->reference(),
                'note' => 'Encaissé par '.$shipment->courier?->name,
            ], $actor);
            $this->couriers->collected($shipment, $payment, $method);
        } elseif ($collected > 0) {
            $this->orders->recordPayment($order, [
                'amount' => $collected,
                'method' => PaymentMethod::Cash,
                'kind' => PaymentKind::CarrierCod,
                'reference' => $shipment->carrier_tracking_code,
                'note' => 'Encaissé par '.($shipment->connection?->name ?? 'l\'entreprise de livraison'),
            ], $actor);
        }
    }

    private function event(Shipment $shipment, ShipmentStatus $status, ?string $raw, string $source, ?string $note, ?User $actor, ?Carbon $at = null): void
    {
        ShipmentEvent::create([
            'store_id' => $shipment->store_id,
            'shipment_id' => $shipment->id,
            'status' => $status,
            'raw_status' => $raw,
            'source' => $source,
            'note' => $note ? Str::limit($note, 490) : null,
            'user_id' => $actor?->id,
            'occurred_at' => $at ?? now(),
        ]);
    }

    /**
     * Emplacement de départ : choisi, sinon celui de la commande, sinon l'entrepôt du transporteur s'il a tout.
     */
    private function origin(Order $order, CarrierConnection $connection, ?int $locationId): ?StockLocation
    {
        $id = $locationId ?? $order->location_id ?? $connection->setting('pickup_location_id');

        return $id ? StockLocation::forStore($order->store_id)->find($id) : null;
    }

    /**
     * Champs NewOrder de Delivery App (docs/PLAN.md §5.3).
     *
     * @return array<string, mixed>
     */
    private function payload(Shipment $shipment, Order $order, CarrierConnection $connection, ?StockLocation $origin, string $feePayer, int $due): array
    {
        $zone = $this->connections->zoneForPlace($connection, $order->shipping_place_id);
        $fromHub = $origin && $origin->type === StockLocationType::CarrierHub && $origin->carrier_connection_id === $connection->id;
        $remoteProducts = $fromHub
            ? CarrierProductMapping::where('connection_id', $connection->id)->whereIn('variant_id', $order->items->pluck('variant_id'))->pluck('remote_product_id', 'variant_id')
            : collect();

        // Livraison payée par la boutique : le client paie exactement le reste dû de la commande, frais compris ;
        // payée par le client : il paie les articles et le tarif du transporteur.
        $itemsAmount = $feePayer === 'store' ? $due : max(0, $due - (int) $order->shipping_fee);

        return array_filter([
            'merchant_reference' => $shipment->reference(),
            'recipient_name' => $order->shipping_name ?? $order->customer_name,
            'recipient_phone' => $order->shipping_phone ?? $order->customer_phone,
            'recipient_phone2' => $order->shipping_phone2,
            'delivery_zone_id' => $zone ? (int) $zone->remote_id : null,
            'delivery_zone' => $zone ? null : $order->shipping_place_label,
            'delivery_address' => $order->shipping_address,
            'delivery_landmark' => $order->shipping_landmark,
            'delivery_scheduled_date' => $order->scheduled_date && $order->scheduled_date->isFuture() ? $order->scheduled_date->toDateString() : null,
            'delivery_time_slot' => $order->time_slot,
            'pickup_hub_id' => $fromHub && $origin->remote_hub_id ? (int) $origin->remote_hub_id : null,
            'pickup_address' => ! $fromHub && $origin ? ($origin->address ?: $order->store->address) : null,
            'pickup_landmark' => ! $fromHub && $origin ? $origin->landmark : null,
            'pickup_contact_name' => ! $fromHub ? $order->store->name : null,
            'pickup_phone' => ! $fromHub ? ($origin?->phone ?: $order->store->phone) : null,
            'description' => Str::limit($order->items->map(fn ($i) => $i->quantity.' × '.$i->label())->implode(', '), 990),
            'weight_kg' => $this->weightKg($order),
            'is_fragile' => $shipment->is_fragile,
            'merchant_note' => $shipment->note,
            'items_amount' => $itemsAmount,
            'fee_payer' => $feePayer === 'store' ? 'merchant' : 'recipient',
            'items' => $order->items->map(fn ($item) => array_filter([
                'product_id' => isset($remoteProducts[$item->variant_id]) ? (int) $remoteProducts[$item->variant_id] : null,
                'label' => Str::limit($item->label(), 250),
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
            ], fn ($v) => $v !== null))->values()->all(),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function weightG(Order $order): ?int
    {
        $total = $order->items->sum(fn ($i) => (int) $i->weight_g * $i->quantity);

        return $total > 0 ? $total : null;
    }

    private function weightKg(Order $order): ?float
    {
        $grams = $this->weightG($order);

        return $grams ? round($grams / 1000, 2) : null;
    }
}
