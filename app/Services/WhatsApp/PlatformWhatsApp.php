<?php

namespace App\Services\WhatsApp;

use App\Exceptions\BusinessRuleException;
use App\Models\PlatformSetting;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WhatsApp du support Jibiat Marchand, branché dans la console : connexion en un clic (le numéro peut rester dans
 * l'application WhatsApp Business du téléphone, « coexistence »), messages reçus, réponses, et messages envoyés depuis
 * le téléphone recopiés dans le fil.
 */
class PlatformWhatsApp
{
    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /**
     * Compte connecté, sous la forme attendue par l'API (jamais enregistré dans la table des boutiques).
     */
    public function account(): ?WhatsappAccount
    {
        $s = PlatformSetting::group('whatsapp');
        if (blank($s['phone_number_id'] ?? null) || blank($s['access_token'] ?? null)) {
            return null;
        }

        return (new WhatsappAccount)->forceFill([
            'connection' => 'embedded',
            'phone_number_id' => $s['phone_number_id'],
            'waba_id' => $s['waba_id'] ?? null,
            'display_phone' => $s['display_phone'] ?? null,
            'access_token' => Crypt::decryptString($s['access_token']),
            'status' => 'active',
        ]);
    }

    public function wabaId(): ?string
    {
        return PlatformSetting::group('whatsapp')['waba_id'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $s = PlatformSetting::group('whatsapp');

        return [
            'connected' => $this->account() !== null,
            'display_phone' => $s['display_phone'] ?? null,
            'name' => $s['name'] ?? null,
            'coexistence' => ($s['coexistence'] ?? null) === '1',
            'connected_at' => $s['connected_at'] ?? null,
            'embedded' => EmbeddedSignup::enabled() ? ['app_id' => config('services.meta.app_id'), 'config_id' => config('services.meta.whatsapp_config_id'), 'graph_version' => WhatsAppCloud::VERSION] : null,
        ];
    }

    /**
     * Résultat de la fenêtre Facebook : jeton, abonnement aux notifications, numéro (activé sauf s'il reste sur le téléphone).
     */
    public function connect(string $code, string $wabaId, ?string $phoneNumberId, bool $coexistence): void
    {
        if (! EmbeddedSignup::enabled()) {
            throw new BusinessRuleException('La connexion en un clic n\'est pas encore activée sur la plateforme (application Meta).');
        }
        try {
            $token = (string) ($this->cloud->graph('GET', 'oauth/access_token', null, [
                'client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'), 'code' => $code,
            ])['access_token'] ?? '');
            if ($token === '') {
                throw new RuntimeException('Meta n\'a pas renvoyé de jeton.');
            }
            $phoneNumberId = $phoneNumberId ?: (string) ($this->cloud->graph('GET', $wabaId.'/phone_numbers', $token)['data'][0]['id'] ?? '');
            if ($phoneNumberId === '') {
                throw new RuntimeException('Aucun numéro trouvé sur ce compte WhatsApp.');
            }
            $this->cloud->graph('POST', $wabaId.'/subscribed_apps', $token);
            $number = $this->cloud->graph('GET', $phoneNumberId, $token, ['fields' => 'display_phone_number,verified_name']);
            if (! $coexistence) {
                $this->cloud->graph('POST', $phoneNumberId.'/register', $token, ['messaging_product' => 'whatsapp', 'pin' => (string) random_int(100000, 999999)]);
            }
        } catch (RuntimeException $e) {
            throw new BusinessRuleException('Connexion à WhatsApp impossible. '.$e->getMessage());
        }

        foreach ([
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'display_phone' => mb_substr((string) ($number['display_phone_number'] ?? ''), 0, 30) ?: null,
            'name' => mb_substr((string) ($number['verified_name'] ?? ''), 0, 120) ?: null,
            'access_token' => Crypt::encryptString($token),
            'coexistence' => $coexistence ? '1' : '0',
            'connected_at' => now()->toIso8601String(),
        ] as $key => $value) {
            PlatformSetting::put("whatsapp.{$key}", $value);
        }
    }

    public function disconnect(): void
    {
        PlatformSetting::where('key', 'like', 'whatsapp.%')->delete();
    }

    /**
     * Notification de Meta pour le compte du support : messages reçus, statuts, et messages envoyés depuis le téléphone.
     *
     * @param  array<string, mixed>  $entry
     */
    public function handle(array $entry): void
    {
        foreach ($entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];
            foreach ($value['messages'] ?? [] as $message) {
                $this->receive($message, $value['contacts'] ?? []);
            }
            foreach ($value['message_echoes'] ?? [] as $echo) {
                $this->echo($echo);
            }
            foreach ($value['statuses'] ?? [] as $status) {
                if (in_array($status['status'] ?? null, ['sent', 'delivered', 'read', 'failed'], true)) {
                    SupportMessage::where('provider_message_id', (string) ($status['id'] ?? ''))->where('direction', 'out')->update(array_filter([
                        'status' => $status['status'],
                        'error' => $status['status'] === 'failed' ? mb_substr((string) ($status['errors'][0]['title'] ?? 'Échec'), 0, 250) : null,
                    ]));
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  list<array<string, mixed>>  $contacts
     */
    public function receive(array $message, array $contacts = []): ?SupportMessage
    {
        $from = (string) ($message['from'] ?? '');
        $body = Inbox::readable($message);
        if ($from === '' || $body === null || $this->known($message['id'] ?? null)) {
            return null;
        }
        $sentAt = $this->time($message);
        $name = collect($contacts)->firstWhere('wa_id', $from)['profile']['name'] ?? null;

        return DB::transaction(function () use ($from, $name, $body, $message, $sentAt) {
            $conversation = SupportConversation::lockForUpdate()->firstOrNew(['contact' => '+'.ltrim($from, '+')]);
            $conversation->fill([
                'contact_name' => $name ? Str::limit($name, 117) : $conversation->contact_name,
                'last_preview' => Str::limit($body, 197),
                'last_message_at' => $sentAt,
                'last_inbound_at' => $sentAt,
                'unread_count' => $conversation->unread_count + 1,
            ])->save();

            return $conversation->messages()->create([
                'direction' => 'in', 'type' => $this->type($message), 'body' => $body,
                'provider_message_id' => $message['id'] ?? null, 'status' => 'received', 'sent_at' => $sentAt,
            ]);
        });
    }

    /**
     * Réponse depuis la console (dans les 24 h qui suivent le dernier message du client, règle de Meta).
     */
    public function reply(SupportConversation $conversation, string $text, User $user): SupportMessage
    {
        if (! $conversation->canReply()) {
            throw new BusinessRuleException('Le client n\'a pas écrit depuis plus de 24 h : WhatsApp n\'autorise plus de réponse libre. Répondez depuis votre téléphone.', 'body');
        }
        $account = $this->account() ?? throw new BusinessRuleException('Le WhatsApp du support n\'est plus connecté.', 'body');
        try {
            $id = $this->cloud->sendText($account, $conversation->contact, $text);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage(), 'body');
        }
        $conversation->forceFill(['last_preview' => Str::limit($text, 197), 'last_message_at' => now()])->save();

        return $conversation->messages()->create([
            'direction' => 'out', 'type' => 'text', 'body' => $text, 'provider_message_id' => $id ?: null,
            'status' => 'sent', 'user_id' => $user->id, 'sent_at' => now(),
        ]);
    }

    /**
     * Message envoyé depuis l'application WhatsApp Business du téléphone (coexistence) : recopié dans le fil.
     *
     * @param  array<string, mixed>  $echo
     */
    private function echo(array $echo): void
    {
        $to = (string) ($echo['to'] ?? '');
        $body = Inbox::readable($echo);
        if ($to === '' || $body === null || $this->known($echo['id'] ?? null)) {
            return;
        }
        $sentAt = $this->time($echo);
        $conversation = SupportConversation::firstOrNew(['contact' => '+'.ltrim($to, '+')]);
        $conversation->fill(['last_preview' => Str::limit($body, 197), 'last_message_at' => $sentAt])->save();
        $conversation->messages()->create([
            'direction' => 'out', 'type' => $this->type($echo), 'body' => $body,
            'provider_message_id' => $echo['id'] ?? null, 'status' => 'sent', 'sent_at' => $sentAt,
        ]);
    }

    public function markRead(SupportConversation $conversation): void
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }
    }

    private function known(?string $providerId): bool
    {
        return $providerId !== null && SupportMessage::where('provider_message_id', $providerId)->exists();
    }

    private function time(array $message): Carbon
    {
        return isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();
    }

    private function type(array $message): string
    {
        $type = $message['type'] ?? '';

        return in_array($type, ['text', 'image', 'audio', 'video', 'document', 'location', 'sticker', 'button', 'interactive'], true) ? $type : 'other';
    }
}
