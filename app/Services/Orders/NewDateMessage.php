<?php

namespace App\Services\Orders;

use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Services\WhatsApp\Inbox;
use RuntimeException;

/**
 * Prévenir le client de la nouvelle date de livraison (après une livraison ratée) : par le WhatsApp de la boutique
 * quand c'est possible (WhatsApp relié, ou client qui a écrit depuis moins de 24 h au numéro officiel) ; sinon un lien
 * WhatsApp avec le message prêt, à envoyer depuis le téléphone.
 */
class NewDateMessage
{
    public function __construct(private readonly MessageTexts $texts, private readonly Inbox $inbox) {}

    /**
     * @return array{sent: bool, text: string, whatsapp_url: ?string}
     */
    public function send(Order $order, User $user): array
    {
        if (! $order->customer_phone) {
            throw new BusinessRuleException('Cette commande n\'a pas de numéro de téléphone.');
        }
        $text = $this->texts->render($order, 'new_date');
        $conversation = Conversation::forStore($order->store_id)->where('channel', 'whatsapp')->where('contact', $order->customer_phone)->first();
        if ($conversation !== null) {
            try {
                $this->inbox->reply($conversation, $text, $user);

                return ['sent' => true, 'text' => $text, 'whatsapp_url' => null];
            } catch (RuntimeException) {
                // Pas de réponse libre possible : message prêt à envoyer depuis le téléphone
            }
        }

        return ['sent' => false, 'text' => $text, 'whatsapp_url' => 'https://wa.me/'.ltrim($order->customer_phone, '+').'?text='.rawurlencode($text)];
    }
}
