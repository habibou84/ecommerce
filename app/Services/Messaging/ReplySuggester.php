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
 * livraison de la boutique. Elle n'est jamais envoyée seule : le marchand la relit, la corrige et l'envoie.
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

    public function __construct(
        private readonly Assistant $assistant,
        private readonly CatalogContext $catalog,
    ) {}

    /**
     * @return array{reply: string, to_check: ?string}
     */
    public function suggest(Conversation $conversation, User $user): array
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

        $draft = $this->assistant->run($store, $user, 'reply', self::SYSTEM, $content, [
            'type' => 'object',
            'properties' => ['reply' => ['type' => 'string'], 'to_check' => ['type' => ['string', 'null']]],
            'required' => ['reply', 'to_check'],
            'additionalProperties' => false,
        ], 4000);

        return [
            'reply' => Str::limit(trim((string) ($draft['reply'] ?? '')), 4000, ''),
            'to_check' => filled($draft['to_check'] ?? null) ? trim($draft['to_check']) : null,
        ];
    }

    private function orderText(Order $order): string
    {
        $order->loadMissing('items');

        return "Commande liée {$order->reference()} : {$order->status->label()}, total ".number_format((int) $order->total, 0, ',', ' ').' F ('
            .$order->items->map(fn ($i) => "{$i->quantity} × {$i->title}")->implode(', ').')';
    }
}
