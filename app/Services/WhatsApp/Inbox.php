<?php

namespace App\Services\WhatsApp;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\SocialAccount;
use App\Models\Store;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Models\WhatsappLink;
use App\Notifications\CustomerMessage;
use App\Services\Accounts\TeamNotifier;
use App\Services\Messaging\AutoReplier;
use App\Services\Social\MetaSocial;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Boîte « Messages » : les messages des clients reçus sur le WhatsApp de la boutique sont rangés par conversation,
 * l'équipe y répond (dans les 24 h permises par Meta, sans limite par le WhatsApp relié par QR code) et crée la commande
 * depuis la conversation.
 */
class Inbox
{
    public function __construct(private readonly WhatsAppCloud $cloud, private readonly TeamNotifier $team, private readonly MetaSocial $social, private readonly WhatsAppBridge $bridge) {}

    /**
     * Message Messenger (page Facebook) ou Instagram reçu d'un client : même boîte que WhatsApp, un fil par client.
     *
     * @param  array<string, mixed>  $event  évènement « messaging » de Meta
     */
    public function receiveSocial(SocialAccount $account, array $event): ?ConversationMessage
    {
        $message = $event['message'] ?? null;
        $from = (string) ($event['sender']['id'] ?? '');
        if (! is_array($message) || ! empty($message['is_echo']) || $from === '' || $from === $account->external_id) {
            return null;
        }
        $providerId = $message['mid'] ?? null;
        if ($providerId && ConversationMessage::forStore($account->store_id)->where('provider_message_id', $providerId)->exists()) {
            return null;
        }
        $attachment = $message['attachments'][0]['type'] ?? null;
        $body = trim((string) ($message['text'] ?? '')) ?: match ($attachment) {
            'image' => '📷 Photo', 'video' => '🎬 Vidéo', 'audio' => '🎤 Message vocal', 'file' => '📄 Document',
            null => null, default => 'Message non affichable ici : ouvrez '.SocialAccount::NETWORKS[$account->network].' pour le voir.',
        };
        if ($body === null) {
            return null;
        }
        $channel = $account->network === 'facebook' ? 'messenger' : 'instagram';
        $sentAt = isset($event['timestamp']) ? Carbon::createFromTimestampMs((int) $event['timestamp']) : now();
        $known = Conversation::forStore($account->store_id)->where('channel', $channel)->where('contact', $from)->value('contact_name');
        $name = $known ?? $this->social->profileName($account, $from);

        [$created, $conversation, $alert] = DB::transaction(function () use ($account, $channel, $from, $name, $body, $providerId, $sentAt, $attachment) {
            $conversation = Conversation::forStore($account->store_id)->lockForUpdate()->firstOrNew(['channel' => $channel, 'contact' => $from]);
            $alert = $conversation->unread_count === 0 || $conversation->last_inbound_at === null || $conversation->last_inbound_at->lt($sentAt->copy()->subMinutes(10));
            $conversation->fill([
                'store_id' => $account->store_id,
                'contact_name' => $name ? Str::limit($name, 117) : $conversation->contact_name,
                'last_preview' => Str::limit($body, 197),
                'last_message_at' => $sentAt,
                'last_inbound_at' => $sentAt,
                'unread_count' => $conversation->unread_count + 1,
            ])->save();

            $created = $conversation->messages()->create([
                'store_id' => $account->store_id, 'direction' => 'in', 'type' => $attachment ? (in_array($attachment, ['image', 'video', 'audio'], true) ? $attachment : 'other') : 'text',
                'body' => $body, 'provider_message_id' => $providerId, 'status' => 'received', 'sent_at' => $sentAt,
            ]);

            return [$created, $conversation, $alert];
        });

        if ($alert && $account->store) {
            $this->team->notify($account->store, Permission::OrdersConfirm->value, new CustomerMessage($conversation, $body));
        }

        return $created;
    }

    /**
     * Message reçu d'un client (notification de Meta). Une notification rejouée n'est enregistrée qu'une fois.
     *
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $contacts
     */
    public function receive(WhatsappAccount $account, array $message, array $contacts = []): ?ConversationMessage
    {
        $from = (string) ($message['from'] ?? '');
        if (($message['type'] ?? null) === 'order' && $from !== '') {
            $items = collect($message['order']['product_items'] ?? [])->map(fn ($i) => [
                'ref' => $i['product_retailer_id'] ?? null, 'quantity' => $i['quantity'] ?? 0, 'price' => $i['item_price'] ?? null,
            ])->all();
            $name = collect($contacts)->firstWhere('wa_id', $from)['profile']['name'] ?? null;

            return $this->receiveOrder($account->store_id, '+'.ltrim($from, '+'), $name, $items, null, $message['order']['text'] ?? null,
                $message['id'] ?? null, isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now(), false);
        }
        $body = self::readable($message);
        if ($from === '' || $body === null) {
            return null;
        }
        $providerId = $message['id'] ?? null;
        if ($providerId && ConversationMessage::forStore($account->store_id)->where('provider_message_id', $providerId)->exists()) {
            return null;
        }

        $name = collect($contacts)->firstWhere('wa_id', $from)['profile']['name'] ?? null;
        $sentAt = isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();
        $type = in_array($message['type'] ?? '', ['text', 'image', 'audio', 'video', 'document', 'location', 'sticker', 'button', 'interactive'], true) ? $message['type'] : 'other';

        return $this->land($account->store_id, '+'.ltrim($from, '+'), $name, $type, $body, $providerId, $sentAt, false);
    }

    /**
     * Message d'un client reçu par le WhatsApp relié par QR code (service whatsapp-bridge).
     *
     * @param  array{id: string, from: string, name?: ?string, kind?: ?string, text?: ?string, lat?: mixed, lng?: mixed, at?: mixed, items?: mixed, total?: mixed}  $event
     */
    public function receiveLinked(WhatsappLink $link, array $event): ?ConversationMessage
    {
        $kind = (string) ($event['kind'] ?? 'text');
        $text = isset($event['text']) ? trim((string) $event['text']) : null;
        if ($kind === 'order') {
            $from = '+'.ltrim((string) ($event['from'] ?? ''), '+');

            return $from === '+' ? null : $this->receiveOrder($link->store_id, $from, $event['name'] ?? null, is_array($event['items'] ?? null) ? $event['items'] : [],
                is_numeric($event['total'] ?? null) ? (int) $event['total'] : null, $text, (string) ($event['id'] ?? '') ?: null,
                isset($event['at']) ? Carbon::createFromTimestamp((int) $event['at']) : now(), true);
        }
        $body = self::linkedBody($event);
        $from = '+'.ltrim((string) ($event['from'] ?? ''), '+');
        $id = (string) ($event['id'] ?? '');
        if ($body === null || $body === '' || $from === '+' || $id === '' || ConversationMessage::forStore($link->store_id)->where('provider_message_id', $id)->exists()) {
            return null;
        }
        $sentAt = isset($event['at']) ? Carbon::createFromTimestamp((int) $event['at']) : now();
        $type = in_array($kind, ['text', 'image', 'audio', 'video', 'document', 'location', 'sticker'], true) ? $kind : 'other';

        return $this->land($link->store_id, $from, $event['name'] ?? null, $type, $body, $id, $sentAt, true);
    }

    /**
     * Texte lisible d'un message reçu par le WhatsApp relié (boîte Messages et support de la plateforme).
     *
     * @param  array<string, mixed>  $event
     */
    public static function linkedBody(array $event): ?string
    {
        $text = isset($event['text']) ? trim((string) $event['text']) : null;
        $caption = fn (string $label) => trim($label.($text ? ' : '.$text : ''));

        return match ((string) ($event['kind'] ?? 'text')) {
            'text' => $text,
            'image' => $caption('📷 Photo'),
            'video' => $caption('🎬 Vidéo'),
            'audio' => '🎤 Message vocal',
            'sticker' => 'Autocollant',
            'document' => $caption('📄 Document'),
            'location' => self::location(['latitude' => $event['lat'] ?? null, 'longitude' => $event['lng'] ?? null, 'name' => $text]),
            default => 'Message non affichable ici : ouvrez WhatsApp pour le voir.',
        };
    }

    /**
     * Message écrit depuis le téléphone de la boutique (WhatsApp relié) : il apparaît aussi dans le fil, s'il existe.
     */
    public function fromPhone(WhatsappLink $link, string $to, string $text, string $id): ?ConversationMessage
    {
        $text = trim($text);
        if ($text === '' || $id === '' || ConversationMessage::forStore($link->store_id)->where('provider_message_id', $id)->exists()) {
            return null;
        }
        $conversation = Conversation::forStore($link->store_id)->where('channel', 'whatsapp')->where('contact', '+'.ltrim($to, '+'))->first();

        return $conversation ? $this->record($conversation, 'text', $text, 'sent', $id) : null;
    }

    /**
     * Panier du catalogue WhatsApp : commande « à confirmer » créée tout de suite et reliée à la conversation, où le
     * panier apparaît en clair. Une notification rejouée ne crée pas une deuxième commande.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function receiveOrder(int $storeId, string $phone, ?string $name, array $items, ?int $total, ?string $text, ?string $providerId, Carbon $sentAt, bool $viaLink): ?ConversationMessage
    {
        $store = Store::find($storeId);
        if ($store === null || ($providerId && ConversationMessage::forStore($storeId)->where('provider_message_id', $providerId)->exists())) {
            return null;
        }
        ['order' => $order, 'body' => $body] = app(CatalogOrders::class)->place($store, $phone, $name, $items, $total, $text);
        $message = $this->land($storeId, $phone, $name, 'order', $body, $providerId, $sentAt, $viaLink);
        if ($order !== null) {
            $message->conversation->forceFill(['order_id' => $order->id])->save();
        }

        return $message;
    }

    private function land(int $storeId, string $phone, ?string $name, string $type, string $body, ?string $providerId, Carbon $sentAt, bool $viaLink): ConversationMessage
    {
        $received = DB::transaction(function () use ($storeId, $phone, $name, $type, $body, $providerId, $sentAt, $viaLink) {
            $conversation = Conversation::forStore($storeId)->lockForUpdate()->firstOrNew(['channel' => 'whatsapp', 'contact' => $phone]);
            // Alerte à l'équipe pour un nouveau fil de discussion, pas à chaque message d'une conversation en cours
            $alert = $conversation->unread_count === 0 || $conversation->last_inbound_at === null || $conversation->last_inbound_at->lt($sentAt->copy()->subMinutes(10));
            $conversation->fill([
                'store_id' => $storeId,
                'contact_name' => $name ? Str::limit($name, 117) : $conversation->contact_name,
                'customer_id' => $conversation->customer_id ?? Customer::forStore($storeId)->where('phone', $phone)->value('id'),
                'last_preview' => Str::limit($body, 197),
                'last_message_at' => $sentAt,
                'last_inbound_at' => $sentAt,
                'unread_count' => $conversation->unread_count + 1,
            ])->forceFill(['via_link' => $viaLink])->save();

            $created = $conversation->messages()->create([
                'store_id' => $storeId,
                'direction' => 'in',
                'type' => $type,
                'body' => $body,
                'provider_message_id' => $providerId,
                'status' => 'received',
                'sent_at' => $sentAt,
            ]);

            return [$created, $conversation, $alert];
        });

        [$created, $conversation, $alert] = $received;
        $store = $alert ? Store::find($storeId) : null;
        if ($store) {
            $this->team->notify($store, Permission::OrdersConfirm->value, new CustomerMessage($conversation, $body));
        }
        app(AutoReplier::class)->schedule($created);

        return $created;
    }

    /**
     * Réponse de l'équipe, envoyée tout de suite : une erreur de Meta est montrée à la personne qui répond.
     */
    public function reply(Conversation $conversation, string $text, User $user): ConversationMessage
    {
        // WhatsApp relié par QR code : la réponse part du téléphone de la boutique, sans limite de 24 h
        $link = $this->linkFor($conversation);
        if ($link !== null) {
            try {
                $id = $this->bridge->send($link, $conversation->contact, $text);
            } catch (RuntimeException $e) {
                throw new BusinessRuleException($e->getMessage(), 'body');
            }

            // En file dans le service : « envoyé » quand WhatsApp l'a pris, puis remis, lu ou en échec
            return $this->record($conversation, 'text', $text, 'queued', $id, $user);
        }
        if (! $conversation->canReply()) {
            throw new BusinessRuleException('Le client n\'a pas écrit depuis plus de 24 h : '.$this->label($conversation).' n\'autorise plus de réponse libre. Répondez depuis votre téléphone ou attendez son prochain message.', 'body');
        }
        if ($conversation->channel !== 'whatsapp') {
            $account = SocialAccount::forStore($conversation->store_id)->where('network', $conversation->channel === 'messenger' ? 'facebook' : 'instagram')->first()
                ?? throw new BusinessRuleException($this->label($conversation).' n\'est plus connecté (Marketing › Réseaux sociaux).', 'body');
            try {
                $id = $this->social->send($account, $conversation->contact, $text);
            } catch (RuntimeException $e) {
                throw new BusinessRuleException($e->getMessage(), 'body');
            }

            return $this->record($conversation, 'text', $text, 'sent', $id, $user);
        }
        $account = WhatsappAccount::forStore($conversation->store_id)->first();
        if ($account === null) {
            throw new BusinessRuleException('Le WhatsApp de votre espace marchand n\'est plus connecté (Réglages › WhatsApp).', 'body');
        }

        try {
            $id = $this->cloud->sendText($account, $conversation->contact, $text);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage(), 'body');
        }

        return $this->record($conversation, 'text', $text, 'sent', $id, $user);
    }

    /**
     * Réponse automatique (réglages des réponses automatiques) : même chemin que celle de l'équipe, sans auteur.
     * Une erreur d'envoi est remontée telle quelle (RuntimeException ou BusinessRuleException).
     */
    public function replyAutomatically(Conversation $conversation, string $text): ConversationMessage
    {
        $link = $this->linkFor($conversation);
        if ($link !== null) {
            return $this->record($conversation, 'auto', $text, 'queued', $this->bridge->send($link, $conversation->contact, $text));
        }
        $account = $conversation->channel === 'whatsapp' && $conversation->canReply() ? WhatsappAccount::forStore($conversation->store_id)->first() : null;
        if ($account === null) {
            throw new BusinessRuleException('Aucun WhatsApp connecté ne permet de répondre à ce client.');
        }

        return $this->record($conversation, 'auto', $text, 'sent', $this->cloud->sendText($account, $conversation->contact, $text));
    }

    /**
     * Message automatique (confirmation, suivi…) envoyé à un client qui a déjà une conversation : il apparaît dans le fil.
     */
    public function logAutomatic(int $storeId, string $phone, string $label): void
    {
        $conversation = Conversation::forStore($storeId)->where('channel', 'whatsapp')->where('contact', $phone)->first();
        if ($conversation !== null) {
            $this->record($conversation, 'template', $label, 'sent');
        }
    }

    /**
     * Statut d'un message envoyé (remis, lu, échec), d'après Meta.
     */
    public function updateStatus(int $storeId, string $providerId, string $status, ?string $error): void
    {
        if (! in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        ConversationMessage::forStore($storeId)->where('provider_message_id', $providerId)->where('direction', 'out')
            ->update(array_filter(['status' => $status, 'error' => $error]));
    }

    /**
     * WhatsApp relié (QR code ou numéro) par lequel répondre, sans limite de 24 h : celui de la boutique s'il est
     * connecté. Seule exception : le client a écrit en dernier à un autre numéro, celui de la connexion officielle de
     * Meta ; la réponse part alors de ce numéro-là, pour ne pas venir d'un numéro qu'il ne connaît pas.
     */
    public function linkFor(Conversation $conversation): ?WhatsappLink
    {
        if ($conversation->channel !== 'whatsapp') {
            return null;
        }
        $link = WhatsappLink::connectedFor($conversation->store_id);
        if ($link === null || $conversation->via_link) {
            return $link;
        }
        $official = WhatsappAccount::forStore($conversation->store_id)->value('display_phone');
        $digits = fn (?string $phone) => substr((string) preg_replace('/\D/', '', (string) $phone), -10);

        return $official === null || $digits($official) === '' || $digits($official) === $digits($link->phone) ? $link : null;
    }

    private function label(Conversation $conversation): string
    {
        return ['messenger' => 'Messenger', 'instagram' => 'Instagram'][$conversation->channel] ?? 'WhatsApp';
    }

    public function markRead(Conversation $conversation): void
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
    }

    private function record(Conversation $conversation, string $type, string $body, string $status, ?string $providerId = null, ?User $user = null): ConversationMessage
    {
        $conversation->forceFill(['last_preview' => Str::limit($body, 197), 'last_message_at' => now()])->save();

        return $conversation->messages()->create([
            'store_id' => $conversation->store_id,
            'direction' => 'out',
            'type' => $type,
            'body' => $body,
            'provider_message_id' => $providerId,
            'status' => $status,
            'user_id' => $user?->id,
            'sent_at' => now(),
        ]);
    }

    /**
     * Texte lisible d'un message WhatsApp ; null pour ce qui n'a pas sa place dans le fil (réactions, messages système).
     *
     * @param  array<string, mixed>  $m
     */
    public static function readable(array $m): ?string
    {
        $caption = fn (string $label, ?string $text) => trim($label.($text ? ' : '.$text : ''));

        return match ($m['type'] ?? null) {
            'text' => (string) ($m['text']['body'] ?? ''),
            'button' => (string) ($m['button']['text'] ?? $m['button']['payload'] ?? ''),
            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
            'image' => $caption('📷 Photo', $m['image']['caption'] ?? null),
            'video' => $caption('🎬 Vidéo', $m['video']['caption'] ?? null),
            'audio' => '🎤 Message vocal',
            'sticker' => 'Autocollant',
            'document' => $caption('📄 Document', $m['document']['filename'] ?? $m['document']['caption'] ?? null),
            'location' => self::location($m['location'] ?? []),
            'reaction', 'system', 'unsupported', 'request_welcome' => null,
            default => 'Message non affichable ici : ouvrez WhatsApp pour le voir.',
        };
    }

    /**
     * @param  array<string, mixed>  $l
     */
    private static function location(array $l): string
    {
        $place = collect([$l['name'] ?? null, $l['address'] ?? null])->filter()->implode(', ');
        $map = isset($l['latitude'], $l['longitude']) ? "https://maps.google.com/?q={$l['latitude']},{$l['longitude']}" : '';

        return trim('📍 Position'.($place ? ' : '.$place : '')."\n".$map);
    }
}
