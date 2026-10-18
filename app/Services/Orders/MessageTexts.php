<?php

namespace App\Services\Orders;

use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;

/**
 * Textes des messages au client, modifiables par le marchand, avec des mots entre accolades remplacés par les
 * informations de la commande ({prenom}, {total}…). Une ligne dont un mot remplacé est vide (ex. {reste} quand
 * tout est payé) disparaît. Les modèles WhatsApp de Meta, eux, ne se modifient pas ici : Meta doit les approuver.
 */
class MessageTexts
{
    public const MESSAGES = [
        'confirm_request' => [
            'label' => 'Demande de confirmation (depuis votre WhatsApp)',
            'text' => "Bonjour {prenom}, merci pour votre commande {commande} chez {boutique} :\n{articles}\n\nTotal : {total} ({adresse}).\n\nPouvez-vous nous confirmer la commande et votre disponibilité ? Répondez « OUI » pour confirmer.",
        ],
        'whatsapp_confirmation' => [
            'label' => 'Demande de confirmation automatique (boutons Confirmer / Annuler, dans la conversation)',
            'text' => "Bonjour {prenom}, merci pour votre commande {commande} chez {boutique} :\n{articles}\n\nTotal : {total} ({adresse}).\n\nConfirmez-vous la commande ?",
        ],
        'confirmed' => [
            'label' => 'Commande confirmée',
            'text' => "Bonjour {prenom}, votre commande {commande} est confirmée ✅\nTotal : {total}\nReste à payer : {reste}\nSuivez-la ici : {suivi}",
        ],
        'payment_reminder' => [
            'label' => 'Rappel de paiement',
            'text' => 'Bonjour {prenom}, pour la commande {commande}, le reste à payer est de {reste}. Merci !',
        ],
        'shipped' => [
            'label' => 'Commande en route',
            'text' => "Bonjour {prenom}, votre commande {commande} est en route 🚚\nMontant à payer à la livraison : {reste}\nSuivi : {suivi}",
        ],
        'thanks' => [
            'label' => 'Remerciement',
            'text' => 'Bonjour {prenom}, merci pour votre achat chez {boutique} ! Nous espérons que vous êtes satisfait(e). À bientôt 🙏',
        ],
        'review_request' => [
            'label' => 'Demande d\'avis',
            'text' => "Bonjour {prenom}, merci d'avoir commandé chez {boutique} ! Votre avis sur les articles reçus nous aide beaucoup ⭐\n{lien_avis}",
        ],
    ];

    public const PLACEHOLDERS = [
        'prenom' => 'prénom du client',
        'boutique' => 'nom de la boutique',
        'commande' => 'numéro de commande',
        'articles' => 'liste des articles',
        'total' => 'total',
        'reste' => 'reste à payer (ligne retirée si tout est payé)',
        'adresse' => 'livraison ou retrait',
        'suivi' => 'lien de suivi',
        'lien_avis' => 'lien pour donner son avis',
    ];

    public function text(Store $store, string $key): string
    {
        return ($store->message_texts[$key] ?? null) ?: self::MESSAGES[$key]['text'];
    }

    public function render(Order $order, string $key, ?string $text = null): string
    {
        $order->loadMissing(['items', 'store']);
        $values = $this->values($order);
        $lines = [];
        foreach (explode("\n", $text ?? $this->text($order->store, $key)) as $line) {
            preg_match_all('/\{(\w+)\}/', $line, $m);
            $used = array_intersect($m[1], array_keys($values));
            if ($used !== [] && collect($used)->contains(fn ($p) => $values[$p] === '')) {
                continue;
            }
            $lines[] = strtr($line, collect($values)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => $v])->all());
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * @param  array<string, ?string>  $texts  texte par message ; vide = texte d'origine
     */
    public function save(Store $store, array $texts): void
    {
        $saved = $store->message_texts ?? [];
        foreach ($texts as $key => $text) {
            if (! isset(self::MESSAGES[$key])) {
                continue;
            }
            $text = trim((string) $text);
            if ($text === '' || $text === self::MESSAGES[$key]['text']) {
                unset($saved[$key]);

                continue;
            }
            preg_match_all('/\{(\w+)\}/', $text, $m);
            if ($unknown = array_diff($m[1], array_keys(self::PLACEHOLDERS))) {
                throw new BusinessRuleException('Mot inconnu dans « '.self::MESSAGES[$key]['label'].' » : {'.implode('}, {', $unknown).'}.', "texts.{$key}");
            }
            $saved[$key] = Str::limit($text, 1500, '');
        }
        $store->forceFill(['message_texts' => $saved ?: null])->save();
    }

    /**
     * @return array<string, string>
     */
    private function values(Order $order): array
    {
        return [
            'prenom' => $order->customer_name ? (string) strtok($order->customer_name, ' ') : 'cher client',
            'boutique' => $order->store->name,
            'commande' => $order->reference(),
            'articles' => $order->items->map(fn ($i) => "• {$i->quantity} × {$i->label()}")->implode("\n"),
            'total' => Money::format($order->total),
            'reste' => $order->amountDue() > 0 ? Money::format($order->amountDue()) : '',
            'adresse' => $order->delivery_method === 'pickup' ? 'à retirer en boutique' : 'à livrer à '.trim(($order->shipping_place_label ?? '').' '.($order->shipping_address ?? '')),
            'suivi' => $order->trackingUrl(),
            'lien_avis' => $order->store->url('/avis/'.$order->tracking_token),
        ];
    }
}
