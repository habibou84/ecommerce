<?php

namespace App\Services\WhatsApp;

use App\Exceptions\BusinessRuleException;
use App\Models\PhoneVerification;
use App\Models\PlatformSetting;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Models\WhatsappAccount;
use App\Services\Accounts\PhoneVerifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use RuntimeException;

/**
 * WhatsApp du support Jibiat Marchand, branché dans la console : connexion en un clic (le numéro peut rester dans
 * l'application WhatsApp Business du téléphone, « coexistence ») ou par QR code (service whatsapp-bridge, comme
 * WhatsApp Web), messages reçus, réponses, et messages envoyés depuis le téléphone recopiés dans le fil. Ce numéro
 * reçoit aussi les messages de vérification des numéros (inscription, connexion…), sans SMS.
 */
class PlatformWhatsApp
{
    public function __construct(private readonly WhatsAppCloud $cloud, private readonly WhatsAppBridge $bridge) {}

    /**
     * Relié par QR code (session « platform » du service whatsapp-bridge) et en ligne.
     */
    public function isQr(): bool
    {
        $s = PlatformSetting::group('whatsapp');

        return ($s['mode'] ?? null) === 'qr' && ($s['qr_status'] ?? null) === 'connected';
    }

    public function connected(): bool
    {
        return $this->isQr() || $this->account() !== null;
    }

    /**
     * Numéro du support relié (par QR code ou avec Facebook), au format international ; null sinon.
     */
    public function phone(): ?string
    {
        if (! $this->connected()) {
            return null;
        }

        return PhoneNumber::normalize(PlatformSetting::group('whatsapp')['display_phone'] ?? null);
    }

    /**
     * Compte connecté, sous la forme attendue par l'API (jamais enregistré dans la table des boutiques).
     */
    public function account(): ?WhatsappAccount
    {
        $s = PlatformSetting::group('whatsapp');
        if (($s['mode'] ?? 'embedded') === 'qr' || blank($s['phone_number_id'] ?? null) || blank($s['access_token'] ?? null)) {
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
            'connected' => $this->connected(),
            // embedded (Facebook) ou qr (QR code) ; null tant que rien n'est relié
            'mode' => $this->connected() || ($s['qr_status'] ?? null) === 'pending' ? ($s['mode'] ?? 'embedded') : null,
            'qr_status' => $s['qr_status'] ?? null,
            'error' => $s['error'] ?? null,
            'display_phone' => $s['display_phone'] ?? null,
            'name' => $s['name'] ?? null,
            'coexistence' => ($s['coexistence'] ?? null) === '1',
            'connected_at' => $s['connected_at'] ?? null,
            'embedded' => EmbeddedSignup::enabled() ? ['app_id' => config('services.meta.app_id'), 'config_id' => config('services.meta.whatsapp_config_id'), 'graph_version' => WhatsAppCloud::VERSION] : null,
            'bridge' => WhatsAppBridge::configured(),
            // SMS (payants) : codes de vérification partis ce mois et le mois dernier, et secours activé ou non
            'sms' => [
                'month' => $this->smsCount(now()->startOfMonth(), now()->startOfMonth()->addMonth()),
                'last_month' => $this->smsCount(now()->subMonthNoOverflow()->startOfMonth(), now()->startOfMonth()),
                'fallback' => (PlatformSetting::find('sms.fallback')?->value) === 'on',
                'ready' => ! in_array(config('services.sms.driver'), ['log', 'none', null], true),
            ],
        ];
    }

    /** SMS proposé même quand WhatsApp est possible (réglage « sms.fallback ») : chaque SMS coûte */
    public function setSmsFallback(bool $on): void
    {
        PlatformSetting::put('sms.fallback', $on ? 'on' : 'off');
    }

    private function smsCount(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) PhoneVerification::query()->where('sms_sent_at', '>=', $from)->where('sms_sent_at', '<', $to)->sum('sms_count');
    }

    /**
     * Connexion par QR code : démarre (ou reprend) la session du service et renvoie son état, avec le QR code à
     * scanner depuis « Appareils connectés » tant que le téléphone n'est pas relié.
     *
     * @return array{status: string, qr: ?string, phone: ?string, name: ?string}
     */
    public function startQr(): array
    {
        if ($this->account() !== null) {
            throw new BusinessRuleException('Le numéro du support est déjà connecté avec Facebook : déconnectez-le d\'abord.');
        }
        PlatformSetting::put('whatsapp.mode', 'qr');
        if (! $this->isQr()) {
            PlatformSetting::put('whatsapp.qr_status', 'pending');
        }
        try {
            $live = $this->bridge->startSession(WhatsAppBridge::PLATFORM);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage(), 'qr');
        }
        $this->applyBridgeStatus($live);

        return $live;
    }

    /**
     * Liaison avec le numéro plutôt que le QR code : code à saisir sur le téléphone.
     *
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function pairQr(string $phone): array
    {
        if ($this->account() !== null) {
            throw new BusinessRuleException('Le numéro du support est déjà connecté avec Facebook : déconnectez-le d\'abord.');
        }
        PlatformSetting::put('whatsapp.mode', 'qr');
        if (! $this->isQr()) {
            PlatformSetting::put('whatsapp.qr_status', 'pending');
        }
        try {
            $live = $this->bridge->pairSession(WhatsAppBridge::PLATFORM, $phone);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage(), 'phone');
        }
        $this->applyBridgeStatus($live);

        return $live;
    }

    /**
     * @return array{status: string, qr: ?string, code: ?string, phone: ?string, name: ?string}
     */
    public function qrStatus(): array
    {
        try {
            $live = $this->bridge->statusSession(WhatsAppBridge::PLATFORM);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException($e->getMessage(), 'qr');
        }
        $this->applyBridgeStatus($live);

        return $live;
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
            'mode' => 'embedded',
            'qr_status' => null,
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
        if ((PlatformSetting::group('whatsapp')['mode'] ?? null) === 'qr') {
            try {
                $this->bridge->logoutSession(WhatsAppBridge::PLATFORM);
            } catch (RuntimeException) {
                // Service injoignable : la connexion est tout de même marquée coupée
            }
        }
        PlatformSetting::where('key', 'like', 'whatsapp.%')->delete();
    }

    /**
     * Événement du service whatsapp-bridge (connexion par QR code) : état de la session, message reçu, accusés.
     *
     * @param  array<string, mixed>  $event
     */
    public function handleBridge(array $event): void
    {
        match ((string) ($event['type'] ?? '')) {
            'status' => $this->applyBridgeStatus(['status' => (string) ($event['status'] ?? ''), 'phone' => $event['phone'] ?? null, 'name' => $event['name'] ?? null, 'qr' => null], $event['error'] ?? null),
            'message' => $this->receiveBridge($event),
            'outgoing' => $this->fromPhone((string) ($event['to'] ?? ''), (string) ($event['text'] ?? ''), (string) ($event['id'] ?? '')),
            'sent', 'delivered', 'read' => SupportMessage::where('provider_message_id', (string) ($event['id'] ?? ''))->where('direction', 'out')->update(['status' => $event['type']]),
            'failed' => SupportMessage::where('provider_message_id', (string) ($event['id'] ?? ''))->where('direction', 'out')->update([
                'status' => 'failed', 'error' => mb_substr((string) ($event['error'] ?? 'Échec'), 0, 250),
            ]),
            default => null,
        };
    }

    /**
     * @param  array{status: string, phone: ?string, name: ?string, qr?: ?string}  $live
     */
    private function applyBridgeStatus(array $live, ?string $error = null): void
    {
        match ($live['status']) {
            'connected' => collect([
                'whatsapp.mode' => 'qr',
                'whatsapp.qr_status' => 'connected',
                'whatsapp.display_phone' => $live['phone'] ? PhoneNumber::display(PhoneNumber::normalize($live['phone']) ?? $live['phone']) : null,
                'whatsapp.name' => $live['name'] ? mb_substr((string) $live['name'], 0, 120) : null,
                'whatsapp.connected_at' => now()->toIso8601String(),
                'whatsapp.error' => null,
            ])->each(fn ($value, $key) => PlatformSetting::put($key, $value)),
            'logged_out' => collect([
                'whatsapp.qr_status' => 'disconnected',
                'whatsapp.error' => $error ?? 'Téléphone déconnecté : scannez un nouveau QR code.',
            ])->each(fn ($value, $key) => PlatformSetting::put($key, $value)),
            'disconnected' => PlatformSetting::put('whatsapp.error', $error),
            default => null,
        };
    }

    /**
     * Message envoyé depuis le téléphone (application WhatsApp) : recopié dans le fil de la console.
     */
    private function fromPhone(string $to, string $text, string $id): void
    {
        $to = '+'.ltrim($to, '+');
        if ($to === '+' || $text === '' || ($id !== '' && $this->known($id))) {
            return;
        }
        $conversation = SupportConversation::firstOrNew(['contact' => $to]);
        $conversation->fill(['last_preview' => Str::limit($text, 197), 'last_message_at' => now()])->save();
        $conversation->messages()->create([
            'direction' => 'out', 'type' => 'text', 'body' => $text, 'provider_message_id' => $id ?: null, 'status' => 'sent', 'sent_at' => now(),
        ]);
    }

    /**
     * Message reçu par le service whatsapp-bridge : code de vérification d'un numéro (réponse immédiate, hors
     * messagerie) ou message pour le support.
     *
     * @param  array<string, mixed>  $event
     */
    private function receiveBridge(array $event): void
    {
        $id = (string) ($event['id'] ?? '');
        $from = '+'.ltrim((string) ($event['from'] ?? ''), '+');
        $text = isset($event['text']) && is_string($event['text']) && $event['text'] !== '' ? $event['text'] : null;
        if ($id === '' || $from === '+' || $this->known($id)) {
            return;
        }

        if ($text !== null && ($answer = app(PhoneVerifier::class)->handleWhatsApp($from, $text)) !== null) {
            try {
                $this->bridge->sendFrom(WhatsAppBridge::PLATFORM, $from, $answer);
            } catch (RuntimeException) {
                // Service injoignable : la vérification est faite, la réponse manquera seulement
            }

            return;
        }

        $kind = (string) ($event['kind'] ?? 'text');
        $body = $text ?? match ($kind) {
            'image' => '📷 Photo (à voir sur le téléphone)',
            'video' => '🎬 Vidéo (à voir sur le téléphone)',
            'audio' => '🎤 Message vocal (à écouter sur le téléphone)',
            'document' => '📄 Document (à voir sur le téléphone)',
            'location' => '📍 Position (à voir sur le téléphone)',
            default => 'Message non affichable ici : ouvrez WhatsApp pour le voir.',
        };
        $sentAt = isset($event['at']) && is_numeric($event['at']) ? Carbon::createFromTimestamp((int) $event['at']) : now();
        $name = isset($event['name']) && is_string($event['name']) ? Str::limit($event['name'], 117) : null;

        DB::transaction(function () use ($from, $name, $body, $id, $kind, $sentAt) {
            $conversation = SupportConversation::lockForUpdate()->firstOrNew(['contact' => $from]);
            $conversation->fill([
                'contact_name' => $name ?? $conversation->contact_name,
                'last_preview' => Str::limit($body, 197),
                'last_message_at' => $sentAt,
                'last_inbound_at' => $sentAt,
                'unread_count' => $conversation->unread_count + 1,
            ])->save();
            $conversation->messages()->create([
                'direction' => 'in', 'type' => in_array($kind, ['text', 'image', 'audio', 'video', 'document', 'location', 'sticker'], true) ? $kind : 'other',
                'body' => $body, 'provider_message_id' => $id, 'status' => 'received', 'sent_at' => $sentAt,
            ]);
        });
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
        // Code de vérification d'un numéro (inscription, connexion…) : réponse immédiate, hors messagerie
        if (($message['type'] ?? null) === 'text' && ($answer = app(PhoneVerifier::class)->handleWhatsApp('+'.ltrim($from, '+'), $body)) !== null) {
            if (($account = $this->account()) !== null) {
                try {
                    $this->cloud->sendText($account, '+'.ltrim($from, '+'), $answer);
                } catch (RuntimeException) {
                    // La vérification est faite, la réponse manquera seulement
                }
            }

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
    /**
     * Message du support à un numéro (alerte à l'équipe : paiement à valider…). Relié par QR code : message libre ;
     * avec Facebook, WhatsApp n'autorise un message libre que dans les 24 h qui suivent un message de ce numéro.
     *
     * @return string|null null si le message est parti, sinon la raison
     */
    public function notify(string $phone, string $text): ?string
    {
        $phone = PhoneNumber::normalize($phone);
        if ($phone === null) {
            return 'Numéro invalide.';
        }
        if (! $this->connected()) {
            return 'Le WhatsApp du support n\'est pas relié (Console › WhatsApp).';
        }
        try {
            if ($this->isQr()) {
                $this->bridge->sendFrom(WhatsAppBridge::PLATFORM, $phone, $text);
            } else {
                $this->cloud->sendText($this->account(), $phone, $text);
            }

            return null;
        } catch (RuntimeException $e) {
            return 'WhatsApp a refusé le message : '.mb_substr($e->getMessage(), 0, 200);
        }
    }

    public function reply(SupportConversation $conversation, string $text, User $user): SupportMessage
    {
        if ($this->isQr()) {
            try {
                $id = $this->bridge->sendFrom(WhatsAppBridge::PLATFORM, $conversation->contact, $text);
            } catch (RuntimeException $e) {
                throw new BusinessRuleException($e->getMessage(), 'body');
            }
            $conversation->forceFill(['last_preview' => Str::limit($text, 197), 'last_message_at' => now()])->save();

            return $conversation->messages()->create([
                'direction' => 'out', 'type' => 'text', 'body' => $text, 'provider_message_id' => $id ?: null,
                'status' => 'sent', 'user_id' => $user->id, 'sent_at' => now(),
            ]);
        }

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
