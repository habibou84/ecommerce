<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Jibiat\Socle\Money;

/**
 * Messages au client, envoyés par le marchand depuis son WhatsApp (lien wa.me prérempli, gratuit).
 * L'envoi automatique par l'API WhatsApp Business arrive en phase 6, avec des modèles modifiables.
 */
class OrderMessages
{
    /**
     * Messages utiles selon le statut de la commande.
     *
     * @return list<array{key: string, label: string, text: string, url: string}>
     */
    public function suggestions(Order $order): array
    {
        if (blank($order->customer_phone)) {
            return []; // client anonyme au comptoir
        }

        $keys = match ($order->status) {
            OrderStatus::PendingConfirmation => ['confirm_request'],
            OrderStatus::Confirmed, OrderStatus::Processing => ['confirmed', 'payment_reminder'],
            OrderStatus::Shipped => ['shipped'],
            OrderStatus::Completed => ['thanks'],
            default => [],
        };

        if ($order->amountDue() === 0) {
            $keys = array_values(array_diff($keys, ['payment_reminder']));
        }

        return array_map(fn (string $key) => $this->message($order, $key), $keys);
    }

    /**
     * @return array{key: string, label: string, text: string, url: string}
     */
    public function message(Order $order, string $key): array
    {
        $order->loadMissing(['items', 'store']);
        $store = $order->store->name;
        $hello = 'Bonjour'.($order->customer_name ? ' '.strtok($order->customer_name, ' ') : '');
        $items = $order->items->map(fn ($i) => "• {$i->quantity} × {$i->label()}")->implode("\n");
        $where = $order->delivery_method === 'pickup' ? 'à retirer en boutique' : 'à livrer à '.trim(($order->shipping_place_label ?? '').' '.($order->shipping_address ?? ''));

        [$label, $text] = match ($key) {
            'confirm_request' => ['Demander la confirmation', "{$hello}, merci pour votre commande {$order->reference()} chez {$store} :\n{$items}\n\nTotal : ".Money::format($order->total)." ({$where}).\n\nPouvez-vous nous confirmer la commande et votre disponibilité ? Répondez « OUI » pour confirmer."],
            'confirmed' => ['Commande confirmée', "{$hello}, votre commande {$order->reference()} est confirmée ✅\nTotal : ".Money::format($order->total).($order->amountDue() < $order->total ? ' (reste à payer : '.Money::format($order->amountDue()).')' : '')."\nSuivez-la ici : {$order->trackingUrl()}"],
            'payment_reminder' => ['Rappel de paiement', "{$hello}, pour la commande {$order->reference()}, le reste à payer est de ".Money::format($order->amountDue()).'. Merci !'],
            'shipped' => ['Commande expédiée', "{$hello}, votre commande {$order->reference()} est en route 🚚\n".($order->amountDue() > 0 ? 'Montant à payer à la livraison : '.Money::format($order->amountDue())."\n" : '')."Suivi : {$order->trackingUrl()}"],
            'thanks' => ['Remerciement', "{$hello}, merci pour votre achat chez {$store} ! Nous espérons que vous êtes satisfait(e). À bientôt 🙏"],
        };

        return ['key' => $key, 'label' => $label, 'text' => $text, 'url' => $this->whatsappUrl($order->customer_phone, $text)];
    }

    public function whatsappUrl(string $phone, string $text = ''): string
    {
        return 'https://wa.me/'.ltrim($phone, '+').($text !== '' ? '?text='.rawurlencode($text) : '');
    }
}
