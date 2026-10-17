<?php

namespace App\Services\WhatsApp;

use App\Enums\ConfirmationOutcome;
use App\Enums\OrderStatus;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OutboundMessage;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\Orders\OrderDesk;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use Throwable;

/**
 * Messages WhatsApp automatiques aux clients, envoyés depuis le numéro WhatsApp Business du marchand :
 * demande de confirmation (boutons Confirmer / Annuler), expédition, livraison, lien de paiement, panier abandonné.
 */
class CustomerMessenger
{
    /**
     * Événements, avec les variables du modèle Meta dans l'ordre ({{1}}, {{2}}…) et ses boutons.
     */
    public const EVENTS = [
        'order_confirmation' => ['label' => 'Demande de confirmation (boutons Confirmer / Annuler)', 'variables' => ['nom du client', 'n° de commande', 'montant'], 'buttons' => ['Confirmer', 'Annuler']],
        'order_shipped' => ['label' => 'Commande en route', 'variables' => ['nom du client', 'n° de commande', 'lien de suivi'], 'buttons' => []],
        'order_delivered' => ['label' => 'Commande livrée (remerciement)', 'variables' => ['nom du client', 'n° de commande'], 'buttons' => []],
        'review_request' => ['label' => 'Demande d\'avis (24 h après la livraison)', 'variables' => ['nom du client', 'boutique', 'lien de l\'avis'], 'buttons' => []],
        'payment_link' => ['label' => 'Lien de paiement', 'variables' => ['nom du client', 'n° de commande', 'montant', 'lien'], 'buttons' => []],
        'cart_recovery' => ['label' => 'Relance de panier abandonné', 'variables' => ['nom du client', 'boutique', 'lien du panier'], 'buttons' => []],
        'daily_report' => ['label' => 'Rapport du jour (envoyé à l\'équipe)', 'variables' => ['boutique', 'date', 'commandes reçues', 'montant livré', 'à confirmer', 'à expédier'], 'buttons' => []],
    ];

    public function __construct(private readonly OrderDesk $orders, private readonly Inbox $inbox) {}

    public function account(int $storeId, string $event): ?WhatsappAccount
    {
        $account = WhatsappAccount::forStore($storeId)->first();

        return $account?->automates($event) && filled($account->templates[$event] ?? null) ? $account : null;
    }

    /**
     * Journal des commandes → messages (création à confirmer, expédition, livraison).
     */
    public function onOrderEvent(OrderEvent $event): void
    {
        $event->loadMissing('order.store');
        $order = $event->order;
        if ($order === null) {
            return;
        }

        if ($event->type === 'created' && $order->status === OrderStatus::PendingConfirmation) {
            $this->requestConfirmation($order, automatic: true);

            return;
        }

        $kind = match (true) {
            $event->type === 'status_changed' && ($event->data['to'] ?? null) === OrderStatus::Shipped->value => 'order_shipped',
            $event->type === 'status_changed' && ($event->data['to'] ?? null) === OrderStatus::Completed->value => 'order_delivered',
            default => null,
        };
        if ($kind) {
            $this->forOrder($order, $kind);
        }
        if ($kind === 'order_delivered') {
            $this->forOrder($order, 'review_request', delay: now()->addDay());
        }
    }

    public function forOrder(Order $order, string $event, array $extra = [], ?\DateTimeInterface $delay = null): ?OutboundMessage
    {
        $account = $this->account($order->store_id, $event);
        if ($account === null || blank($order->customer_phone)) {
            return null; // pas de WhatsApp, ou client anonyme au comptoir
        }

        $name = Str::before((string) ($order->customer_name ?: 'cher client'), ' ');
        $params = match ($event) {
            'order_confirmation' => [$name, $order->reference(), Money::format($order->total)],
            'order_shipped' => [$name, $order->reference(), $order->trackingUrl()],
            'order_delivered' => [$name, $order->reference()],
            'review_request' => [$name, $order->store->name, $order->store->url('/avis/'.$order->tracking_token)],
            'payment_link' => [$name, $order->reference(), Money::format($extra['amount']), $extra['url']],
        };
        $buttons = $event === 'order_confirmation' ? ["confirm:{$order->id}", "cancel:{$order->id}"] : [];

        return $this->queue($account, $order->customer_phone, $event, $params, $buttons, 'order', $order->id, $delay);
    }

    /**
     * Demande de confirmation par WhatsApp (boutons Confirmer / Annuler) : dans la conversation, gratuitement et sans
     * modèle, si le client a écrit à la boutique depuis moins de 24 h ; sinon par le modèle approuvé.
     * « automatic » : à la création de la commande, seulement si la boutique l'a activé dans ses réglages.
     */
    public function requestConfirmation(Order $order, bool $automatic = false): ?OutboundMessage
    {
        $channel = $this->confirmationChannel($order, $automatic);
        if ($channel === null) {
            return null;
        }
        $account = WhatsappAccount::forStore($order->store_id)->first();
        $buttons = ["confirm:{$order->id}", "cancel:{$order->id}"];

        if ($channel === 'template') {
            $name = Str::before((string) ($order->customer_name ?: 'cher client'), ' ');

            return $this->queue($account, $order->customer_phone, 'order_confirmation', [$name, $order->reference(), Money::format($order->total)], $buttons, 'order', $order->id);
        }

        $text = $this->confirmationText($order);
        $message = OutboundMessage::create([
            'store_id' => $account->store_id, 'channel' => 'whatsapp', 'to_phone' => $order->customer_phone, 'event' => 'order_confirmation',
            'body' => $text, 'related_type' => 'order', 'related_id' => $order->id, 'status' => 'queued',
        ]);
        SendWhatsAppMessage::dispatch($message->id, [], array_combine($buttons, self::EVENTS['order_confirmation']['buttons']), $text)->afterCommit();
        $this->inbox->logAutomatic($account->store_id, $order->customer_phone, '🤖 '.$text.' [Confirmer] [Annuler]');

        return $message;
    }

    /**
     * Comment demander la confirmation de cette commande : « conversation » (gratuit), « template », ou null (impossible).
     */
    public function confirmationChannel(Order $order, bool $automatic = false): ?string
    {
        $account = WhatsappAccount::forStore($order->store_id)->first();
        if ($account === null || $account->status !== 'active' || blank($order->customer_phone) || $order->status !== OrderStatus::PendingConfirmation) {
            return null;
        }
        if ($automatic && ! $account->automates('order_confirmation')) {
            return null;
        }

        $conversation = Conversation::forStore($order->store_id)->where('channel', 'whatsapp')->where('contact', $order->customer_phone)->first();
        if ($conversation?->canReply()) {
            return 'conversation';
        }

        return filled($account->templates['order_confirmation'] ?? null) ? 'template' : null;
    }

    private function confirmationText(Order $order): string
    {
        $order->loadMissing(['items', 'store']);
        $hello = 'Bonjour'.($order->customer_name ? ' '.Str::before($order->customer_name, ' ') : '');
        $items = $order->items->map(fn ($i) => "• {$i->quantity} × {$i->label()}")->implode("\n");
        $where = $order->delivery_method === 'pickup' ? 'à retirer en boutique' : 'à livrer à '.trim(($order->shipping_place_label ?? '').' '.($order->shipping_address ?? ''));

        return "{$hello}, merci pour votre commande {$order->reference()} chez {$order->store->name} :\n{$items}\n\nTotal : ".Money::format($order->total)." ({$where}).\n\nConfirmez-vous la commande ?";
    }

    public function forCart(Cart $cart): ?OutboundMessage
    {
        $account = $this->account($cart->store_id, 'cart_recovery');
        if ($account === null || ! $cart->phone) {
            return null;
        }
        $cart->loadMissing('store');

        return $this->queue($account, $cart->phone, 'cart_recovery', [
            Str::before((string) ($cart->name ?: 'cher client'), ' '), $cart->store->name, $cart->store->url('/panier/reprendre/'.$cart->token),
        ], [], 'cart', $cart->id);
    }

    /**
     * Message à un membre de l'équipe (rapport quotidien), depuis le WhatsApp de la boutique.
     *
     * @param  list<string>  $params
     */
    public function forTeam(Store $store, string $phone, string $event, array $params, string $type, int $id): ?OutboundMessage
    {
        $account = $this->account($store->id, $event);

        return $account && $phone !== '' ? $this->queue($account, $phone, $event, $params, [], $type, $id) : null;
    }

    private function queue(WhatsappAccount $account, string $phone, string $event, array $params, array $buttons, string $type, int $id, ?\DateTimeInterface $delay = null): OutboundMessage
    {
        $message = OutboundMessage::create([
            'store_id' => $account->store_id,
            'channel' => 'whatsapp',
            'to_phone' => $phone,
            'event' => $event,
            'body' => $account->templates[$event].' · '.implode(' · ', $params),
            'related_type' => $type,
            'related_id' => $id,
            'status' => 'queued',
        ]);
        SendWhatsAppMessage::dispatch($message->id, $params, $buttons)->afterCommit()->delay($delay);
        if ($type !== 'report') {
            $this->inbox->logAutomatic($account->store_id, $phone, '🤖 '.Str::before(self::EVENTS[$event]['label'], ' (').' · '.implode(' · ', $params));
        }

        return $message;
    }

    /**
     * Notification de Meta : réponses aux boutons (confirmation de commande) et statuts des messages.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleInbound(WhatsappAccount $account, array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->inbox->updateStatus($account->store_id, (string) ($status['id'] ?? ''), (string) ($status['status'] ?? ''), ($status['status'] ?? null) === 'failed' ? mb_substr((string) ($status['errors'][0]['title'] ?? 'Échec'), 0, 250) : null);
                    $column = ['sent' => 'sent_at', 'delivered' => 'delivered_at', 'read' => 'read_at'][$status['status'] ?? ''] ?? null;
                    OutboundMessage::forStore($account->store_id)->where('provider_message_id', $status['id'] ?? '')->update(array_filter([
                        'status' => $status['status'] ?? null,
                        $column => $column ? now() : null,
                        'error' => ($status['status'] ?? null) === 'failed' ? mb_substr((string) ($status['errors'][0]['title'] ?? 'Échec'), 0, 250) : null,
                    ]));
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $this->inbox->receive($account, $message, $value['contacts'] ?? []);
                    $reply = $message['button']['payload'] ?? $message['interactive']['button_reply']['id'] ?? null;
                    if (is_string($reply) && preg_match('/^(confirm|cancel):(\d+)$/', $reply, $m)) {
                        $this->answer($account, (int) $m[2], $m[1], (string) ($message['from'] ?? ''));
                    }
                }
            }
        }
    }

    /**
     * Le client a répondu par un bouton : la commande est confirmée ou annulée (si elle attend encore).
     */
    private function answer(WhatsappAccount $account, int $orderId, string $choice, string $from): void
    {
        $order = Order::forStore($account->store_id)->find($orderId);
        // Seul le client de la commande peut répondre pour elle
        if ($order === null || ltrim((string) $order->customer_phone, '+') !== ltrim($from, '+') || $order->status !== OrderStatus::PendingConfirmation) {
            return;
        }

        try {
            $this->orders->recordConfirmation($order, $choice === 'confirm' ? ConfirmationOutcome::Confirmed : ConfirmationOutcome::Refused,
                'whatsapp', $choice === 'confirm' ? 'Confirmée par le client sur WhatsApp' : 'Annulée par le client sur WhatsApp', null, null);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
