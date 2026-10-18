<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;

/**
 * Messages au client, envoyés par le marchand depuis son WhatsApp (lien wa.me prérempli, gratuit), avec les
 * textes de la boutique (MessageTexts, modifiables dans Réglages › Textes des messages).
 */
class OrderMessages
{
    private const LABELS = [
        'confirm_request' => 'Demander la confirmation',
        'confirmed' => 'Commande confirmée',
        'payment_reminder' => 'Rappel de paiement',
        'shipped' => 'Commande en route',
        'thanks' => 'Remerciement',
        'review_request' => 'Demander un avis',
    ];

    public function __construct(private readonly MessageTexts $texts) {}

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
            OrderStatus::Completed => ['thanks', 'review_request'],
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
        $text = $this->texts->render($order, $key);

        return ['key' => $key, 'label' => self::LABELS[$key], 'text' => $text, 'url' => $this->whatsappUrl($order->customer_phone, $text)];
    }

    public function whatsappUrl(string $phone, string $text = ''): string
    {
        return 'https://wa.me/'.ltrim($phone, '+').($text !== '' ? '?text='.rawurlencode($text) : '');
    }
}
