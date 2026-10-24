<?php

namespace App\Services\WhatsApp;

use App\Models\PlatformSetting;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WhatsApp du support relié par QR code ou par numéro (session « support » du service whatsapp-bridge), comme
 * WhatsApp Web : utile tant que la connexion officielle de Meta n'est pas possible. Les messages arrivent dans la
 * console, les réponses partent du téléphone du support, sans limite de 24 h. État gardé dans les réglages de la
 * plateforme (whatsapp_link.*).
 */
class SupportLink
{
    public const SESSION = 'support';

    public function __construct(private readonly WhatsAppBridge $bridge) {}

    /**
     * @return array{status: string, phone: ?string, name: ?string, connected_at: ?string, last_error: ?string}
     */
    public function state(): array
    {
        $s = PlatformSetting::group('whatsapp_link');

        return [
            // none : pas relié ; pending : QR code ou code à saisir ; connected : relié ; disconnected : à relier de nouveau
            'status' => $s['status'] ?? 'none',
            'phone' => $s['phone'] ?? null,
            'name' => $s['name'] ?? null,
            'connected_at' => $s['connected_at'] ?? null,
            'last_error' => $s['last_error'] ?? null,
        ];
    }

    public function isConnected(): bool
    {
        return $this->state()['status'] === 'connected';
    }

    /**
     * État pour la console, l'état réel du service faisant foi s'il a manqué un événement.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $live = null;
        if ($this->state()['status'] !== 'none') {
            try {
                $live = $this->bridge->status(self::SESSION);
                $this->sync($live);
            } catch (RuntimeException) {
                // Service injoignable : l'état enregistré reste affiché
            }
        }

        return $this->describe($live);
    }

    /**
     * @return array<string, mixed>
     */
    public function start(): array
    {
        $this->pending();

        return $this->describe($this->bridge->start(self::SESSION));
    }

    /**
     * @return array<string, mixed>
     */
    public function pair(string $phone): array
    {
        $this->pending();

        return $this->describe($this->bridge->pair(self::SESSION, $phone));
    }

    public function logout(): void
    {
        try {
            $this->bridge->logout(self::SESSION);
        } catch (RuntimeException) {
            // Service injoignable : le support est tout de même déconnecté de ce côté
        }
        PlatformSetting::where('key', 'like', 'whatsapp\_link.%')->delete();
    }

    /**
     * Événement « status » du service : QR code scanné, téléphone déconnecté.
     */
    public function status(string $status, ?string $phone, ?string $name, ?string $error): void
    {
        match ($status) {
            'connected' => $this->put(['status' => 'connected', 'phone' => $phone ?: $this->state()['phone'], 'name' => $name ?: $this->state()['name'],
                'connected_at' => now()->toIso8601String(), 'last_error' => null]),
            'logged_out' => $this->put(['status' => 'disconnected', 'last_error' => $error ?: 'Téléphone déconnecté : reliez de nouveau le numéro du support.']),
            'disconnected' => $this->put(['last_error' => $error]),
            default => null,
        };
    }

    /**
     * Message d'un client reçu par le numéro relié.
     *
     * @param  array<string, mixed>  $event
     */
    public function receive(array $event): ?SupportMessage
    {
        $body = Inbox::linkedBody($event);
        $from = '+'.ltrim((string) ($event['from'] ?? ''), '+');
        $id = (string) ($event['id'] ?? '');
        if ($body === null || $body === '' || $from === '+' || $id === '' || SupportMessage::where('provider_message_id', $id)->exists()) {
            return null;
        }
        $sentAt = isset($event['at']) ? Carbon::createFromTimestamp((int) $event['at']) : now();
        $kind = (string) ($event['kind'] ?? 'text');
        $name = filled($event['name'] ?? null) ? Str::limit((string) $event['name'], 117) : null;

        return DB::transaction(function () use ($from, $name, $body, $id, $sentAt, $kind) {
            $conversation = SupportConversation::lockForUpdate()->firstOrNew(['contact' => $from]);
            $conversation->fill([
                'contact_name' => $name ?? $conversation->contact_name,
                'last_preview' => Str::limit($body, 197),
                'last_message_at' => $sentAt,
                'last_inbound_at' => $sentAt,
                'unread_count' => $conversation->unread_count + 1,
            ])->save();

            return $conversation->messages()->create([
                'direction' => 'in', 'type' => in_array($kind, ['text', 'image', 'audio', 'video', 'document', 'location', 'sticker'], true) ? $kind : 'other',
                'body' => $body, 'provider_message_id' => $id, 'status' => 'received', 'sent_at' => $sentAt,
            ]);
        });
    }

    /**
     * Message écrit depuis le téléphone du support : recopié dans le fil, s'il existe.
     */
    public function fromPhone(string $to, string $text, string $id): void
    {
        $text = trim($text);
        $conversation = SupportConversation::where('contact', '+'.ltrim($to, '+'))->first();
        if ($conversation === null || $text === '' || $id === '' || SupportMessage::where('provider_message_id', $id)->exists()) {
            return;
        }
        $conversation->forceFill(['last_preview' => Str::limit($text, 197), 'last_message_at' => now()])->save();
        $conversation->messages()->create(['direction' => 'out', 'type' => 'text', 'body' => $text, 'provider_message_id' => $id, 'status' => 'sent', 'sent_at' => now()]);
    }

    /**
     * Réponse envoyée : remise, lue ou en échec (sans retour en arrière).
     */
    public function advance(string $id, string $status, ?string $error = null): void
    {
        $message = $id === '' ? null : SupportMessage::where('provider_message_id', $id)->where('direction', 'out')->first();
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        if ($message === null || ($rank[$message->status] ?? 0) >= $rank[$status]) {
            return;
        }
        $message->forceFill(['status' => $status, 'error' => $error ? 'WhatsApp : '.$error : null])->save();
    }

    /**
     * Réponse depuis la console, mise en file dans le service.
     */
    public function send(string $to, string $text): string
    {
        return $this->bridge->send(self::SESSION, $to, $text);
    }

    /**
     * @param  array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}|null  $live
     * @return array<string, mixed>
     */
    private function describe(?array $live): array
    {
        $state = $this->state();
        $connected = $state['status'] === 'connected';

        return [
            'available' => WhatsAppBridge::configured(),
            'status' => $state['status'],
            'qr' => $connected ? null : ($live['qr'] ?? null),
            'code' => $connected ? null : ($live['code'] ?? null),
            'store_phone' => null,
            'phone' => $state['phone'],
            'name' => $state['name'],
            'connected_at' => $state['connected_at'],
            'last_error' => $state['last_error'],
        ];
    }

    /**
     * @param  array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}  $live
     */
    private function sync(array $live): void
    {
        if ($live['status'] === 'connected' && ! $this->isConnected()) {
            $this->status('connected', $live['phone'], $live['name'], null);
        } elseif ($live['status'] === 'logged_out' && $this->isConnected()) {
            $this->status('logged_out', null, null, 'La liaison avec WhatsApp a été perdue : reliez de nouveau le numéro du support.');
        }
    }

    private function pending(): void
    {
        if (! $this->isConnected()) {
            $this->put(['status' => 'pending', 'last_error' => null]);
        }
    }

    /**
     * @param  array<string, ?string>  $values
     */
    private function put(array $values): void
    {
        foreach ($values as $key => $value) {
            PlatformSetting::put("whatsapp_link.{$key}", $value);
        }
    }
}
