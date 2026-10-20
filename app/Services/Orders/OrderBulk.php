<?php

namespace App\Services\Orders;

use App\Enums\CancelReason;
use App\Enums\OrderStatus;
use App\Models\CarrierConnection;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Shipping\ShipmentDesk;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Actions sur plusieurs commandes cochées dans la liste. Chaque commande est traitée à part : une commande qui ne
 * s'y prête pas (déjà livrée, déjà confiée…) est notée et n'empêche pas les autres.
 */
class OrderBulk
{
    public function __construct(
        private readonly OrderDesk $orders,
        private readonly ShipmentDesk $shipments,
        private readonly CustomerMessenger $messenger,
    ) {}

    /**
     * @param  Collection<int, Order>  $orders
     * @param  array<string, mixed>  $data
     * @return array{done: int, skipped: list<array{reference: string, reason: string}>}
     */
    public function run(Collection $orders, array $data, ?User $actor): array
    {
        $courier = ! empty($data['courier_id']) ? Courier::where('status', 'active')->find($data['courier_id']) : null;
        $carrier = ! empty($data['carrier_connection_id']) ? CarrierConnection::where('status', 'active')->find($data['carrier_connection_id']) : null;
        if ($data['action'] === 'assign' && ! $courier && ! $carrier) {
            return ['done' => 0, 'skipped' => [['reference' => '—', 'reason' => 'Choisissez un livreur ou une entreprise de livraison.']]];
        }

        $done = 0;
        $skipped = [];
        foreach ($orders as $order) {
            try {
                $reason = match ($data['action']) {
                    'assign' => $this->assign($order, $courier, $carrier, $actor),
                    'status' => $this->status($order, OrderStatus::from($data['status']), $data['reason'] ?? null, $actor),
                    'whatsapp' => $this->whatsapp($order),
                };
            } catch (Throwable $e) {
                $reason = mb_substr($e->getMessage(), 0, 160);
            }
            if ($reason === null) {
                $done++;
            } else {
                $skipped[] = ['reference' => $order->reference(), 'reason' => $reason];
            }
        }

        return ['done' => $done, 'skipped' => $skipped];
    }

    /**
     * Commande à confirmer : le choix est gardé pour la confirmation ; confirmée ou en préparation : course demandée.
     */
    private function assign(Order $order, ?Courier $courier, ?CarrierConnection $carrier, ?User $actor): ?string
    {
        if ($order->delivery_method !== 'delivery') {
            return 'Retrait au magasin';
        }
        if ($order->shipments()->get()->contains(fn (Shipment $s) => ! $s->status->isFinal())) {
            return 'Déjà confiée';
        }
        if ($order->status === OrderStatus::PendingConfirmation) {
            $order->forceFill(['planned_courier_id' => $courier?->id, 'planned_carrier_connection_id' => $courier ? null : $carrier?->id])->save();
            $this->orders->log($order, 'planned_delivery', ['to' => $courier?->name ?? $carrier?->name], $actor);

            return null;
        }
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing], true)) {
            return 'Commande '.mb_strtolower($order->status->label());
        }
        $courier
            ? $this->shipments->assignCourier($order, $courier, ['note' => null], $actor)
            : $this->shipments->create($order, $carrier, [], $actor);

        return null;
    }

    private function status(Order $order, OrderStatus $to, ?string $reason, ?User $actor): ?string
    {
        if ($order->status === $to) {
            return 'Déjà '.mb_strtolower($to->label());
        }
        if (! $order->status->canTransitionTo($to)) {
            return 'Impossible depuis « '.$order->status->label().' »';
        }
        $this->orders->transition($order, $to, $actor, $reason ? ['reason' => CancelReason::from($reason), 'note' => 'Annulée depuis la liste'] : []);

        return null;
    }

    /**
     * Message WhatsApp de l'étape, par le numéro WhatsApp de la boutique : demande de confirmation, ou « en route ».
     */
    private function whatsapp(Order $order): ?string
    {
        $message = match ($order->status) {
            OrderStatus::PendingConfirmation => $this->messenger->requestConfirmation($order),
            OrderStatus::Shipped => $this->messenger->forOrder($order, 'order_shipped'),
            default => false,
        };

        return match (true) {
            $message === false => 'Pas de message prévu à cette étape',
            $message === null => 'WhatsApp de la boutique non connecté ou client sans numéro',
            default => null,
        };
    }
}
