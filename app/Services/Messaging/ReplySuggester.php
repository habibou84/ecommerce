<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\Assistant;
use App\Services\Ai\CatalogContext;
use Illuminate\Support\Str;

/**
 * Réponse WhatsApp proposée par l'assistant IA, avec les vrais prix, le vrai stock et les vrais frais de
 * livraison de la boutique. Le marchand la relit, la corrige et l'envoie ; seules les réponses automatiques (si la
 * boutique les a activées) partent sans relecture, et uniquement quand l'assistant juge la question simple et la
 * réponse entièrement tirée des données de la boutique.
 */
class ReplySuggester
{
    private const SYSTEM = <<<'TXT'
    Tu écris, pour un commerçant de Côte d'Ivoire, la prochaine réponse WhatsApp à son client.
    Style : chaleureux, poli, court (2 à 5 phrases), en français simple, comme un vendeur ivoirien sérieux ; vouvoiement ; un emoji au plus.
    Règles :
    - Utilise uniquement les informations fournies : prix, disponibilité (« épuisé » = ne pas le proposer, suggérer un article proche s'il y en a), frais et délais de livraison par zone, état de la commande.
    - N'invente jamais un prix, une taille, une couleur, un délai ou une promotion. Si l'information manque, dis que tu vérifies et reviens vers le client, et signale-le dans to_check.
    - Si le client veut commander, demande ce qui manque pour livrer (nom, quartier, repère, numéro joignable, taille/couleur) et récapitule le total (articles + livraison) quand tu peux le calculer.
    - Si le client se plaint ou si la demande dépasse la vente (remboursement, litige), réponds avec calme sans rien promettre et signale-le dans to_check.
    - reply : le texte du message seul, sans signature ni guillemets. to_check : ce que le commerçant doit vérifier avant d'envoyer, ou null.
    TXT;

    private const AUTOMATIC = <<<'TXT'
    Mode automatique : ta réponse partira sans relecture du commerçant.
    send_alone = true seulement si les deux conditions sont réunies :
    1. le client salue, demande si un article est disponible, son prix, ses tailles ou couleurs, les frais ou délais de livraison, ou vient d'envoyer un panier du catalogue (commande à compléter) ;
    2. ta réponse s'appuie entièrement sur les informations fournies et ne promet rien d'autre (pas de remise, pas de délai non indiqué, pas d'engagement).
    Dans tous les autres cas, send_alone = false : réclamation, retard, remboursement, négociation du prix, question personnelle ou hors vente, information manquante, message peu clair, photo, vocal ou document sans texte explicite, client agacé.
    TXT;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly CatalogContext $catalog,
    ) {}

    /**
     * @return array{reply: string, to_check: ?string}
     */
    public function suggest(Conversation $conversation, User $user): array
    {
        $draft = $this->draft($conversation, $user, false);

        return ['reply' => $draft['reply'], 'to_check' => $draft['to_check']];
    }

    /**
     * Réponse automatique : send est vrai seulement si l'assistant la juge sûre (rien à vérifier).
     *
     * @return array{reply: string, to_check: ?string, send: bool}
     */
    public function automatic(Conversation $conversation): array
    {
        $draft = $this->draft($conversation, null, true);
        $draft['send'] = $draft['send'] && $draft['to_check'] === null && $draft['reply'] !== '';

        return $draft;
    }

    /**
     * @return array{reply: string, to_check: ?string, send: bool}
     */
    private function draft(Conversation $conversation, ?User $user, bool $automatic): array
    {
        $store = $conversation->store;
        $messages = $conversation->messages()->latest('sent_at')->latest('id')->limit(20)->get()->reverse()->values();
        $transcript = $messages->map(fn (ConversationMessage $m) => ($m->direction === 'in' ? 'Client' : 'Boutique')
            .' ('.$m->sent_at->timezone($store->timezone)->format('d/m H:i').') : '.Str::limit((string) $m->body, 1000))->implode("\n");

        $products = $this->catalog->products($store, $messages->where('direction', 'in')->pluck('body')->implode(' '));
        $order = $conversation->order;

        $content = [['type' => 'text', 'text' => implode("\n\n", array_filter([
            "Boutique : {$store->name}".($store->phone ? " (tél. {$store->phone})" : ''),
            "Catalogue (prix et disponibilité) :\n".$this->catalog->describe($store, $products, withStock: true),
            "Livraison par zone :\n".$this->catalog->zones($store),
            $order ? $this->orderText($order) : null,
            $conversation->contact_name ? "Nom WhatsApp du client : {$conversation->contact_name}" : null,
            "Conversation (la plus récente en dernier) :\n".($transcript !== '' ? $transcript : '(aucun message)'),
        ]))]];

        $properties = ['reply' => ['type' => 'string'], 'to_check' => ['type' => ['string', 'null']]] + ($automatic ? ['send_alone' => ['type' => 'boolean']] : []);
        $draft = $this->assistant->run($store, $user, $automatic ? 'auto_reply' : 'reply', $automatic ? self::SYSTEM."\n".self::AUTOMATIC : self::SYSTEM, $content, [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ], 4000);

        return [
            'reply' => Str::limit(trim((string) ($draft['reply'] ?? '')), 4000, ''),
            'to_check' => filled($draft['to_check'] ?? null) ? trim($draft['to_check']) : null,
            'send' => ($draft['send_alone'] ?? false) === true,
        ];
    }

    private function orderText(Order $order): string
    {
        $order->loadMissing('items');

        return "Commande liée {$order->reference()} : {$order->status->label()}, total ".number_format((int) $order->total, 0, ',', ' ').' F ('
            .$order->items->map(fn ($i) => "{$i->quantity} × {$i->title}")->implode(', ').')';
    }
}
