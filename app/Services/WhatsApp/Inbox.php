<?php

namespace App\Services\WhatsApp;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Notifications\CustomerMessage;
use App\Services\Accounts\TeamNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Boîte « Messages » : les messages des clients reçus sur le WhatsApp de la boutique sont rangés par conversation,
 * l'équipe y répond (dans les 24 h permises par Meta) et crée la commande depuis la conversation.
 */
class Inbox
{
    public function __construct(private readonly WhatsAppCloud $cloud, private readonly TeamNotifier $team) {}

    /**
     * Message reçu d'un client (notification de Meta). Une notification rejouée n'est enregistrée qu'une fois.
     *
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $contacts
     */
    public function receive(WhatsappAccount $account, array $message, array $contacts = []): ?ConversationMessage
    {
        $from = (string) ($message['from'] ?? '');
        $body = $this->body($message);
        if ($from === '' || $body === null) {
            return null;
        }
        $providerId = $message['id'] ?? null;
        if ($providerId && ConversationMessage::forStore($account->store_id)->where('provider_message_id', $providerId)->exists()) {
            return null;
        }

        $phone = '+'.ltrim($from, '+');
        $name = collect($contacts)->firstWhere('wa_id', $from)['profile']['name'] ?? null;
        $sentAt = isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();

        $received = DB::transaction(function () use ($account, $phone, $name, $message, $body, $providerId, $sentAt) {
            $conversation = Conversation::forStore($account->store_id)->lockForUpdate()->firstOrNew(['channel' => 'whatsapp', 'contact' => $phone]);
            // Alerte à l'équipe pour un nouveau fil de discussion, pas à chaque message d'une conversation en cours
            $alert = $conversation->unread_count === 0 || $conversation->last_inbound_at === null || $conversation->last_inbound_at->lt($sentAt->copy()->subMinutes(10));
            $conversation->fill([
                'store_id' => $account->store_id,
                'contact_name' => $name ? Str::limit($name, 117) : $conversation->contact_name,
                'customer_id' => $conversation->customer_id ?? Customer::forStore($account->store_id)->where('phone', $phone)->value('id'),
                'last_preview' => Str::limit($body, 197),
                'last_message_at' => $sentAt,
                'last_inbound_at' => $sentAt,
                'unread_count' => $conversation->unread_count + 1,
            ])->save();

            $created = $conversation->messages()->create([
                'store_id' => $account->store_id,
                'direction' => 'in',
                'type' => in_array($message['type'] ?? '', ['text', 'image', 'audio', 'video', 'document', 'location', 'sticker', 'button', 'interactive'], true) ? $message['type'] : 'other',
                'body' => $body,
                'provider_message_id' => $providerId,
                'status' => 'received',
                'sent_at' => $sentAt,
            ]);

            return [$created, $conversation, $alert];
        });

        [$created, $conversation, $alert] = $received;
        if ($alert && $account->store) {
            $this->team->notify($account->store, Permission::OrdersConfirm->value, new CustomerMessage($conversation, $body));
        }

        return $created;
    }

    /**
     * Réponse de l'équipe, envoyée tout de suite : une erreur de Meta est montrée à la personne qui répond.
     */
    public function reply(Conversation $conversation, string $text, User $user): ConversationMessage
    {
        if (! $conversation->canReply()) {
            throw new BusinessRuleException('Le client n\'a pas écrit depuis plus de 24 h : WhatsApp n\'autorise plus de réponse libre. Répondez depuis votre téléphone ou attendez son prochain message.', 'body');
        }
        $account = WhatsappAccount::forStore($conversation->store_id)->first();
        if ($account === null) {
            throw new BusinessRuleException('Le WhatsApp de la boutique n\'est plus connecté (Réglages › WhatsApp).', 'body');
        }

        try {
            $id = $this->cloud->sendText($account, $conversation->contact, $text);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage(), 'body');
        }

        return $this->record($conversation, 'text', $text, 'sent', $id, $user);
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
    private function body(array $m): ?string
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
            'location' => $this->location($m['location'] ?? []),
            'reaction', 'system', 'unsupported', 'request_welcome' => null,
            default => 'Message non affichable ici : ouvrez WhatsApp pour le voir.',
        };
    }

    /**
     * @param  array<string, mixed>  $l
     */
    private function location(array $l): string
    {
        $place = collect([$l['name'] ?? null, $l['address'] ?? null])->filter()->implode(', ');
        $map = isset($l['latitude'], $l['longitude']) ? "https://maps.google.com/?q={$l['latitude']},{$l['longitude']}" : '';

        return trim('📍 Position'.($place ? ' : '.$place : '')."\n".$map);
    }
}
