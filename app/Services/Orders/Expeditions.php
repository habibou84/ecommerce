<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\Courier;
use App\Models\CourierEarning;
use App\Models\Order;
use App\Models\User;
use App\Services\WhatsApp\CustomerMessenger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use Throwable;

/**
 * Expédition vers une autre ville : le colis est déposé à la gare (par le livreur ou le vendeur), les frais de la
 * compagnie de transport sont connus à ce moment-là. Selon la commande, le client les paie à l'arrivée, avant le
 * départ (ajoutés à la commande) ou la boutique les offre (dépense). Le client est prévenu (compagnie, ticket, frais).
 */
class Expeditions
{
    public const PAYERS = ['arrival' => 'Le client, à l\'arrivée', 'before' => 'Le client, avant le départ', 'store' => 'La boutique (offerts)'];

    public function __construct(private readonly OrderDesk $orders) {}

    /**
     * Reste à payer avant de partir à la gare (paiement avant expédition), 0 si rien n'est demandé.
     */
    public static function blockingDue(Order $order): int
    {
        return $order->isExpedition() && $order->expedition_prepay && $order->expedition_dropped_at === null ? $order->expeditionPrepayDue() : 0;
    }

    /**
     * Dépôt à la gare : compagnie, frais payés, ticket et photo du reçu.
     *
     * @param  array{company: string, fee: int, ticket?: ?string, advanced?: bool}  $data
     * @param  UploadedFile|string|null  $receipt  photo du reçu (ou chemin déjà enregistré par l'étape du livreur)
     */
    public function record(Order $order, array $data, UploadedFile|string|null $receipt, ?User $actor, ?Courier $courier = null): Order
    {
        if (! $order->isExpedition()) {
            throw new BusinessRuleException('Cette commande n\'est pas une expédition vers une autre ville.');
        }
        if (! in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::Shipped], true)) {
            throw new BusinessRuleException('Seule une commande confirmée et pas encore livrée peut être expédiée.');
        }
        $company = trim($data['company']);
        if ($company === '') {
            throw new BusinessRuleException('Indiquez la compagnie de transport.', 'company');
        }

        $order = DB::transaction(function () use ($order, $data, $receipt, $actor, $courier, $company) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fee = (int) $data['fee'];
            $before = $order->expedition_payer === 'before' ? (int) $order->expedition_fee : 0;
            $advanced = $courier !== null && ($data['advanced'] ?? false);

            $order->forceFill([
                'expedition_company' => Str::limit($company, 60, ''),
                'expedition_fee' => $fee,
                'expedition_ticket' => filled($data['ticket'] ?? null) ? Str::limit(trim($data['ticket']), 60, '') : null,
                'expedition_receipt_path' => ($receipt instanceof UploadedFile ? $receipt->store("proofs/{$order->store_id}", 'local') : $receipt) ?? $order->expedition_receipt_path,
                'expedition_advanced' => $advanced,
                'expedition_dropped_at' => $order->expedition_dropped_at ?? now(),
            ]);
            // Payés par le client avant le départ : ajoutés à ce qu'il doit
            if ($order->expedition_payer === 'before') {
                $order->total = $order->total - $before + $fee;
            }
            $order->save();
            $this->orders->refreshPaymentStatus($order);

            // Avancés par le livreur : à lui rembourser avec sa paie
            if ($advanced && $fee > 0) {
                CourierEarning::create([
                    'store_id' => $order->store_id, 'courier_id' => $courier->id, 'shipment_id' => $order->shipments()->latest('id')->value('id'),
                    'type' => 'expedition', 'amount' => $fee, 'note' => 'Frais d\'expédition avancés · '.$order->expedition_company,
                ]);
            }
            // Offerts par la boutique : une dépense de transport (pas avancée par le livreur, déjà comptée dans sa paie)
            if ($order->expedition_payer === 'store' && $fee > 0 && ! $advanced) {
                CashMovement::create([
                    'store_id' => $order->store_id, 'type' => 'expense', 'category' => 'transport', 'amount' => $fee, 'method' => 'other',
                    'note' => 'Expédition '.$order->reference().' · '.$order->expedition_company, 'occurred_at' => now(), 'created_by' => $actor?->id,
                ]);
            }

            if ($order->status->canTransitionTo(OrderStatus::Shipped)) {
                $order = $this->orders->transition($order, OrderStatus::Shipped, $actor, ['note' => 'Déposée à la gare · '.$order->expedition_company]);
            }
            $this->orders->log($order, 'expedition_dropped', ['company' => $order->expedition_company, 'fee' => $fee, 'ticket' => $order->expedition_ticket], $actor);

            return $order;
        });

        try {
            app(CustomerMessenger::class)->forOrder($order->loadMissing('store', 'expeditionCity'), 'order_expedited');
        } catch (Throwable $e) {
            report($e);
        }

        return $order->fresh();
    }

    /**
     * Colis récupéré par le client à la gare : commande terminée.
     */
    public function collected(Order $order, ?User $actor): Order
    {
        if (! $order->isExpedition() || $order->expedition_dropped_at === null) {
            throw new BusinessRuleException('Le colis n\'a pas encore été déposé à la gare.');
        }
        if (! $order->status->canTransitionTo(OrderStatus::Completed)) {
            throw new BusinessRuleException('Cette commande est déjà terminée ou annulée.');
        }

        return $this->orders->transition($order, OrderStatus::Completed, $actor, ['note' => 'Colis récupéré à la gare']);
    }

    /**
     * Phrase sur les frais d'expédition, pour le client.
     */
    public static function feeSentence(Order $order): string
    {
        $fee = Money::format((int) $order->expedition_fee);

        return match ($order->expedition_payer) {
            'before' => "Frais d'expédition : {$fee}, ajoutés à votre commande (reste à payer : ".Money::format($order->amountDue()).').',
            'store' => 'Les frais d\'expédition sont offerts.',
            default => "Frais d'expédition : {$fee}, à payer à l'arrivée.",
        };
    }

    /**
     * Message complet au client (aussi proposé au vendeur pour l'envoyer lui-même).
     */
    public static function message(Order $order): string
    {
        $name = Str::before((string) ($order->customer_name ?: 'cher client'), ' ');

        return "Bonjour {$name}, votre commande {$order->reference()} est partie pour ".($order->expeditionCity?->name ?? 'votre ville')
            .' par '.$order->expedition_company.($order->expedition_ticket ? ', ticket n° '.$order->expedition_ticket : '').'. '
            .self::feeSentence($order).' Présentez ce numéro à la gare pour récupérer votre colis. Merci !';
    }
}
