<?php

namespace App\Services\Messaging;

use App\Jobs\SendAutoReply;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Store;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\Inbox;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Réponses automatiques aux clients WhatsApp, désactivées par défaut. Garde-fous, surtout pour le WhatsApp relié par
 * QR code (connexion non officielle, qui ne doit pas ressembler à un robot) :
 * - réponse seulement à un client qui vient d'écrire, 45 s après son dernier message (il a fini d'écrire) ;
 * - seulement si l'assistant juge la question simple et la réponse tirée des données de la boutique ;
 * - pas de réponse automatique pendant 30 min après une réponse de l'équipe (elle a la main) ;
 * - au plus 6 réponses automatiques par conversation et par jour, et le plafond par heure de la boutique.
 */
class AutoReplier
{
    public const DEFAULTS = ['enabled' => false, 'when' => 'always', 'from' => '19:00', 'to' => '08:00', 'per_hour' => 15];

    public const DELAY_SECONDS = 45;

    public const HUMAN_PAUSE_MINUTES = 30;

    public const PER_CONVERSATION_DAY = 6;

    private const TYPES = ['text', 'order', 'button', 'interactive'];

    public function __construct(private readonly Inbox $inbox, private readonly ReplySuggester $suggester) {}

    /**
     * @return array{enabled: bool, when: string, from: string, to: string, per_hour: int}
     */
    public static function settings(Store $store): array
    {
        $settings = array_merge(self::DEFAULTS, array_intersect_key($store->auto_replies ?? [], self::DEFAULTS));
        $settings['enabled'] = (bool) $settings['enabled'];
        $settings['per_hour'] = max(1, min(60, (int) $settings['per_hour']));

        return $settings;
    }

    /**
     * Message reçu : réponse automatique programmée si la boutique les a activées.
     */
    public function schedule(ConversationMessage $message): void
    {
        if ($message->direction !== 'in' || ! in_array($message->type, self::TYPES, true)) {
            return;
        }
        $store = Store::find($message->store_id);
        if ($store === null || ! self::settings($store)['enabled'] || $message->conversation?->channel !== 'whatsapp') {
            return;
        }

        SendAutoReply::dispatch($message->id)->delay(now()->addSeconds(self::DELAY_SECONDS));
    }

    /**
     * Envoie la réponse si tous les garde-fous le permettent. Renvoie la raison quand rien n'est envoyé, null sinon.
     */
    public function run(int $messageId): ?string
    {
        $incoming = ConversationMessage::withoutGlobalScopes()->find($messageId);
        $store = $incoming ? Store::find($incoming->store_id) : null;
        if ($store === null) {
            return 'missing';
        }
        $settings = self::settings($store);
        if (! $settings['enabled']) {
            return 'disabled';
        }
        if (! $this->inHours($store, $settings)) {
            return 'hours';
        }
        app(Tenancy::class)->setStore($store);
        $conversation = Conversation::forStore($store->id)->find($incoming->conversation_id);
        if ($conversation === null) {
            return 'missing';
        }

        $lock = Cache::lock("auto-reply:{$conversation->id}", 120);
        if (! $lock->get()) {
            return 'busy';
        }

        try {
            return $this->answer($store, $settings, $conversation, $incoming);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{per_hour: int}  $settings
     */
    private function answer(Store $store, array $settings, Conversation $conversation, ConversationMessage $incoming): ?string
    {
        $messages = ConversationMessage::forStore($store->id)->where('conversation_id', $conversation->id);
        // Le client a encore écrit (sa réponse suivante s'en chargera) ou quelqu'un a déjà répondu
        $last = (clone $messages)->latest('sent_at')->latest('id')->first();
        if ($last?->id !== $incoming->id) {
            return 'not_last';
        }
        if ((clone $messages)->where('direction', 'out')->whereNotIn('type', ['auto', 'template'])->where('sent_at', '>=', now()->subMinutes(self::HUMAN_PAUSE_MINUTES))->exists()) {
            return 'team';
        }
        if ((clone $messages)->where('type', 'auto')->where('sent_at', '>=', now()->subDay())->count() >= self::PER_CONVERSATION_DAY) {
            return 'conversation_limit';
        }
        if (ConversationMessage::forStore($store->id)->where('type', 'auto')->where('sent_at', '>=', now()->subHour())->count() >= $settings['per_hour']) {
            return 'hourly_limit';
        }
        if ($this->inbox->linkFor($conversation) === null && ! ($conversation->canReply() && WhatsappAccount::forStore($store->id)->exists())) {
            return 'no_channel';
        }

        try {
            $draft = $this->suggester->automatic($conversation);
        } catch (Throwable $e) {
            Log::info('Réponse automatique : assistant indisponible', ['store' => $store->id, 'error' => $e->getMessage()]);

            return 'assistant';
        }
        if (! $draft['send']) {
            return 'unsure';
        }

        try {
            $this->inbox->replyAutomatically($conversation, $draft['reply']);
        } catch (Throwable $e) {
            Log::warning('Réponse automatique non envoyée', ['store' => $store->id, 'conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return 'send_failed';
        }

        return null;
    }

    /**
     * « En dehors des heures » : de from à to (heure de la boutique), la plage pouvant passer minuit.
     *
     * @param  array{when: string, from: string, to: string}  $settings
     */
    private function inHours(Store $store, array $settings): bool
    {
        if ($settings['when'] !== 'away') {
            return true;
        }
        $now = Carbon::now($store->timezone ?: 'Africa/Abidjan')->format('H:i');
        [$from, $to] = [$settings['from'], $settings['to']];

        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }
}
