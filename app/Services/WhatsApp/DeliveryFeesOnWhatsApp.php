<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Place;
use App\Models\Store;
use App\Services\Live\LiveCheckout;
use App\Services\Places\PlaceMatcher;
use App\Services\Places\Places;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jibiat\Socle\Money;
use Throwable;

/**
 * Prix de livraison sur WhatsApp, pour voir sans commander : bouton « 🛵 Prix de livraison » (sous la liste du live et
 * dans le menu des modèles) ou message « livraison », « c'est combien la livraison à Yop ? ». Liste des communes avec
 * leur prix ; une commune touchée donne ses quartiers ; un lieu nommé donne directement son prix. Toucher une commune
 * ici ne change jamais l'adresse d'une commande. L'assistant se tait 6 h après une réponse de l'équipe.
 */
class DeliveryFeesOnWhatsApp
{
    /** Une liste au plus toutes les 2 minutes par client (demandée par un mot) */
    private const LIST_SECONDS = 120;

    private const PAUSE_HOURS = 6;

    /** Mots qui ne désignent pas un lieu, retirés avant de chercher le lieu nommé */
    private const FILLER = ['c est', 'cest', 'combien', 'la', 'le', 'les', 'de', 'du', 'des', 'pour', 'a', 'au', 'aux', 'en', 'sur', 'vers', 'jusqu',
        'livraison', 'livraisons', 'livrez', 'livrer', 'livre', 'vous', 'tu', 'prix', 'frais', 'cout', 'coute', 'tarif', 'tarifs', 'svp', 'stp', 'bonjour',
        'bonsoir', 'merci', 'et', 'ca', 'quel', 'quels', 'est', 'il', 'y', 'chez', 'moi', 'ici', 'jusque', 'quartier', 'commune', 'faites'];

    public function __construct(private readonly Inbox $inbox, private readonly LiveCheckout $checkout) {}

    /**
     * Message qui parle du prix de la livraison (court message seulement).
     */
    public static function asks(string $text): bool
    {
        $words = self::words($text);

        return $words !== '' && str_word_count($words) <= 14 && preg_match('/(?<![a-z])(livraison|livraisons|livrez|livrer)(?![a-z])/', $words) === 1;
    }

    private static function words(string $text): string
    {
        return Str::of(Str::ascii(mb_strtolower($text)))->replaceMatches('/[^a-z ]+/', ' ')->squish()->value();
    }

    /**
     * Personne de l'équipe n'a répondu dans la conversation ces 6 dernières heures.
     */
    public function enabled(Conversation $conversation): bool
    {
        return $conversation->channel === 'whatsapp' && ConversationMessage::forStore($conversation->store_id)->where('conversation_id', $conversation->id)
            ->where('direction', 'out')->whereNotNull('user_id')->where('created_at', '>=', now()->subHours(self::PAUSE_HOURS))->doesntExist();
    }

    /**
     * « livraison », « c'est combien la livraison à Yop ? » : le prix du lieu nommé, sinon la liste des communes.
     * Vrai si le message a été traité.
     */
    public function fromText(Conversation $conversation, string $text): bool
    {
        if (! self::asks($text) || ! $this->enabled($conversation)) {
            return false;
        }
        $store = $this->store($conversation);
        $rest = collect(explode(' ', self::words($text)))->reject(fn (string $w) => in_array($w, self::FILLER, true) || mb_strlen($w) < 2)->implode(' ');
        $place = mb_strlen($rest) >= 3 ? app(PlaceMatcher::class)->search($rest, app(Places::class)->forStore($store), 1)->first() : null;
        if ($place !== null) {
            return $this->priceOf($conversation, $store, $place);
        }

        return Cache::add('delivery-fees:'.$conversation->id, 1, self::LIST_SECONDS) ? $this->list($conversation) : true;
    }

    /**
     * Touche du client : « fees:menu », « fees:commune:12 », « fees:cities ».
     */
    public function onReply(Conversation $conversation, string $payload): bool
    {
        $parts = explode(':', $payload);
        if (($parts[0] ?? '') !== 'fees') {
            return false;
        }
        $store = $this->store($conversation);

        return match ($parts[1] ?? 'menu') {
            'commune' => ($commune = Place::visibleTo($store->id)->with('parent.parent')->find((int) ($parts[2] ?? 0))) !== null
                ? $this->commune($conversation, $store, $commune) : $this->list($conversation),
            'cities' => $this->cities($conversation, $store),
            default => $this->list($conversation),
        };
    }

    /**
     * Liste des communes livrées avec leur prix (liste à toucher, ou texte sur le WhatsApp relié par QR code).
     */
    public function list(Conversation $conversation): bool
    {
        $store = $this->store($conversation);
        $table = $this->checkout->feeTable($store);
        if ($table['communes'] === [] && $table['cities'] === null) {
            return $this->text($conversation, '🛵 La boutique vous confirme le prix de la livraison selon votre quartier. Écrivez-nous votre commune.');
        }
        $label = fn (array $c) => $c['fee'] === 0 ? 'Livraison offerte' : $this->checkout->feeLabel($c['fee'], $c['from'], $c['eta']);
        $rows = collect($table['communes'])->take($table['cities'] !== null ? 9 : 10)
            ->map(fn (array $c) => ['id' => 'fees:commune:'.$c['id'], 'title' => $c['name'], 'description' => $label($c)]);
        if ($table['cities'] !== null) {
            $rows->push(['id' => 'fees:cities', 'title' => '🚚 Autres villes', 'description' => 'Expédition dès '.Money::format($table['cities'])]);
        }
        $intro = "🛵 *Prix de livraison*\nTouchez votre commune pour voir ses quartiers 👇";
        if ($this->rich($conversation, $intro.' (prix de livraison)', fn (WhatsAppCloud $cloud, $account, string $to) => $cloud->sendList($account, $to, $intro, 'Voir les prix', 'Communes', $rows->values()->all()))) {
            return true;
        }
        $lines = collect($table['communes'])->map(fn (array $c) => '• '.$c['name'].' : '.($c['fee'] === 0 ? 'offerte' : ($c['from'] ? 'dès ' : '').Money::format($c['fee'])));
        if ($table['cities'] !== null) {
            $lines->push('• 🚚 Autres villes : dès '.Money::format($table['cities']));
        }

        return $this->text($conversation, "🛵 *Prix de livraison*\n\n".$lines->implode("\n")."\n\nVotre quartier n'y est pas ? Écrivez-le, on vous répond.");
    }

    /**
     * Une commune : son prix, puis les quartiers qui ont un autre prix.
     */
    private function commune(Conversation $conversation, Store $store, Place $commune): bool
    {
        $quote = $this->checkout->quote($store, $commune);
        $quartiers = $this->checkout->quartiers($store, $commune, 40);
        $base = $quote['fee'];
        $others = $quartiers->filter(fn (array $q) => $q['fee'] !== $base)->map(fn (array $q) => '• '.$q['place']->name.' : '.($q['fee'] === 0 ? 'offerte' : Money::format($q['fee'])));
        $named = $quartiers->filter(fn (array $q) => $q['fee'] === $base)->take(8)->map(fn (array $q) => $q['place']->name);
        $text = "🛵 *{$commune->name}* : ".$this->price($quote['fee']).($quote['eta'] ? ' · '.mb_strtolower($quote['eta']) : '')
            .($named->isNotEmpty() ? "\n".$named->implode(', ').($quartiers->count() > $named->count() + $others->count() ? '…' : '') : '')
            .($others->isNotEmpty() ? "\n\nAutre prix :\n".$others->implode("\n") : '')
            ."\n\nPour commander, envoyez le code de l'article (ex. A1).";

        return $this->text($conversation, $text);
    }

    private function cities(Conversation $conversation, Store $store): bool
    {
        $cities = $this->checkout->cities($store);
        $lines = $cities->map(fn (array $c) => '• '.$c['place']->name.' : '.Money::format($c['fee']));

        return $this->text($conversation, "🚚 *Expédition vers les autres villes*\n\n".($lines->isNotEmpty() ? $lines->implode("\n")."\n\n" : '')
            .'Autre ville : dès '.Money::format((int) ($store->expedition_fee ?? $cities->min('fee') ?? 0)).'. Écrivez-nous votre ville.');
    }

    private function priceOf(Conversation $conversation, Store $store, Place $place): bool
    {
        $quote = $this->checkout->quote($store, $place->loadMissing('parent.parent'));

        return $this->text($conversation, ($quote['expedition'] ? '🚚 Expédition vers' : '🛵 Livraison à').' *'.$place->label().'* : '.$this->price($quote['fee'])
            .($quote['eta'] ? ' · '.mb_strtolower($quote['eta']) : ''));
    }

    private function price(?int $fee): string
    {
        return $fee === null ? 'prix à confirmer par la boutique' : ($fee === 0 ? 'offerte' : Money::format($fee));
    }

    private function store(Conversation $conversation): Store
    {
        return $conversation->store ?? Store::findOrFail($conversation->store_id);
    }

    private function rich(Conversation $conversation, string $preview, callable $send): bool
    {
        try {
            return $this->inbox->replyRich($conversation, $preview, $send) !== null;
        } catch (Throwable $e) {
            Log::info('Prix de livraison WhatsApp : message non envoyé', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function text(Conversation $conversation, string $text): bool
    {
        try {
            $this->inbox->replyAutomatically($conversation, $text);

            return true;
        } catch (Throwable $e) {
            Log::info('Prix de livraison WhatsApp : réponse non envoyée', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
