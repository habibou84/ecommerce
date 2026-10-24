<?php

namespace App\Services\Accounts;

use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\PhoneVerification;
use App\Models\PlatformSetting;
use App\Models\Store;
use App\Models\User;
use App\Services\WhatsApp\PlatformWhatsApp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Sms\SmsException;
use Jibiat\Socle\Sms\SmsGateway;

/**
 * Vérification d'un numéro, pour la création d'une boutique, une invitation, un mot de passe oublié ou une connexion :
 *  - WhatsApp (gratuit, d'abord) : la personne envoie depuis son WhatsApp un message prérempli contenant un code au
 *    numéro du support Jibiat Marchand (relié dans la console, par QR code ou avec Facebook) ; l'expéditeur prouve le
 *    numéro ;
 *  - SMS (payant, dernier recours) : un code secret envoyé au numéro et saisi sur la page, proposé seulement quand
 *    aucun numéro WhatsApp n'est relié (ou si la console autorise le SMS en secours, réglage « sms.fallback »).
 * Le navigateur reçoit un jeton secret qui désigne la demande.
 */
class PhoneVerifier
{
    public const TTL_MINUTES = 30;

    public const MAX_ATTEMPTS = 5;

    public const MAX_SMS = 3;

    public const SMS_COOLDOWN_SECONDS = 60;

    // Par numéro et par heure
    public const MAX_STARTS_PER_HOUR = 5;

    public const MAX_SMS_PER_HOUR = 4;

    // Début du message WhatsApp prérempli, reconnu à la réception
    public const WHATSAPP_PREFIX = 'Code de vérification';

    public function __construct(private readonly SmsGateway $sms, private readonly PlatformWhatsApp $whatsapp) {}

    /**
     * Ouvre une vérification. Sans WhatsApp relié, le premier code SMS part tout de suite.
     *
     * @param  array<string, mixed>|null  $payload  données à conserver jusqu'à la fin (chiffrées)
     * @return array{0: PhoneVerification, 1: string, 2: ?string} vérification, jeton du navigateur, code (démonstration)
     */
    public function start(VerificationPurpose $purpose, string $phone, ?Store $store = null, ?User $user = null, ?array $payload = null, ?string $ip = null): array
    {
        $phone = PhoneNumber::normalize($phone) ?? throw new BusinessRuleException('Numéro de téléphone invalide.', 'phone');

        if ($this->whatsappNumber() === null && ! $this->smsAvailable($phone)) {
            throw new BusinessRuleException('Pour le moment, seuls les numéros mobiles de Côte d\'Ivoire (01, 05, 07) sont acceptés.', 'phone');
        }

        if (PhoneVerification::where('phone', $phone)->where('created_at', '>', now()->subHour())->count() >= self::MAX_STARTS_PER_HOUR) {
            throw new BusinessRuleException('Trop de demandes pour ce numéro. Réessayez dans une heure.', 'phone');
        }

        $token = Str::random(48);

        $verification = DB::transaction(function () use ($purpose, $phone, $store, $user, $payload, $ip, $token) {
            // Une seule demande en cours par numéro et par usage
            PhoneVerification::where('purpose', $purpose->value)->where('phone', $phone)->pending()->update(['expires_at' => now()]);

            return PhoneVerification::create([
                'purpose' => $purpose,
                'phone' => $phone,
                'store_id' => $store?->id,
                'user_id' => $user?->id,
                'token_hash' => PhoneVerification::hashToken($token),
                'whatsapp_code' => $this->uniqueWhatsAppCode(),
                'payload' => $payload,
                'ip' => $ip,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        // WhatsApp possible : rien n'est envoyé, la personne envoie elle-même le message (aucun SMS)
        return [$verification, $token, $this->whatsappNumber() === null && $this->smsOffered($verification) ? $this->sendSms($verification) : null];
    }

    public function find(string $token): ?PhoneVerification
    {
        return PhoneVerification::where('token_hash', PhoneVerification::hashToken($token))->first();
    }

    /**
     * Numéro WhatsApp du support auquel envoyer le message de vérification ; null si aucun n'est relié.
     */
    public function whatsappNumber(): ?string
    {
        return $this->whatsapp->phone();
    }

    /**
     * Texte prérempli du message WhatsApp et lien qui l'ouvre.
     *
     * @return array{number: string, text: string, link: string}|null
     */
    public function whatsappInstructions(PhoneVerification $verification): ?array
    {
        $number = $this->whatsappNumber();
        if ($number === null || $verification->whatsapp_code === null) {
            return null;
        }
        $text = self::WHATSAPP_PREFIX.' : '.$verification->whatsapp_code;

        return [
            'number' => PhoneNumber::display($number),
            'text' => $text,
            'link' => 'https://wa.me/'.ltrim($number, '+').'?text='.rawurlencode($text),
        ];
    }

    /**
     * SMS proposé pour cette vérification : seulement quand WhatsApp n'est pas possible (aucun numéro relié), sauf
     * si la console autorise le SMS en secours pour tout le monde (« sms.fallback »).
     */
    public function smsOffered(PhoneVerification $verification): bool
    {
        if (! $this->smsAvailable($verification->phone)) {
            return false;
        }

        return $this->whatsappNumber() === null || PlatformSetting::find('sms.fallback')?->value === 'on';
    }

    /**
     * Les SMS ne partent que vers les mobiles du pays : les numéros étrangers sont refusés pour couper court
     * à la fraude aux SMS surtaxés.
     */
    public function smsAvailable(string $phone): bool
    {
        return config('services.sms.driver') !== 'none' && PhoneNumber::isLocalMobile($phone);
    }

    /**
     * Aucun envoi réel (développement) : le code est renvoyé à la page. Jamais en production.
     */
    public function demoMode(): bool
    {
        return config('services.sms.driver') === 'log' && ! app()->isProduction();
    }

    /**
     * Envoie un (nouveau) code. Renvoie le code en mode démonstration, null sinon.
     */
    public function sendSms(PhoneVerification $verification): ?string
    {
        $this->ensureOpen($verification);

        if (! $this->smsOffered($verification)) {
            throw new BusinessRuleException('L\'envoi de SMS n\'est pas possible vers ce numéro. Utilisez la vérification par WhatsApp.');
        }

        $wait = $this->smsWaitSeconds($verification);
        if ($wait > 0) {
            throw new BusinessRuleException("Patientez {$wait} secondes avant de demander un nouveau code.");
        }

        if ($verification->sms_count >= self::MAX_SMS
            || PhoneVerification::where('phone', $verification->phone)->where('sms_sent_at', '>', now()->subHour())->sum('sms_count') >= self::MAX_SMS_PER_HOUR) {
            throw new BusinessRuleException('Nombre maximal de SMS atteint pour ce numéro. Réessayez dans une heure.');
        }

        $code = (string) random_int(100000, 999999);
        $verification->forceFill([
            'sms_code_hash' => Hash::make($code),
            'sms_count' => $verification->sms_count + 1,
            'sms_sent_at' => now(),
            'attempts' => 0,
        ])->save();

        // Mot de passe oublié ou connexion pour un numéro sans compte : rien n'est envoyé, sans le révéler
        if (in_array($verification->purpose, [VerificationPurpose::PasswordReset, VerificationPurpose::Login], true) && $verification->user_id === null) {
            return null;
        }

        try {
            $this->sms->send($verification->phone, $this->smsText($verification, $code));
        } catch (SmsException $e) {
            Log::warning('SMS de vérification non envoyé', ['phone' => $verification->phone, 'error' => $e->getMessage()]);
            $verification->forceFill(['sms_count' => $verification->sms_count - 1, 'sms_sent_at' => null])->save();

            throw new BusinessRuleException('Le SMS n\'a pas pu être envoyé. Réessayez dans un instant.');
        }

        return $this->demoMode() ? $code : null;
    }

    public function smsWaitSeconds(PhoneVerification $verification): int
    {
        if ($verification->sms_sent_at === null) {
            return 0;
        }

        return max(0, self::SMS_COOLDOWN_SECONDS - (int) $verification->sms_sent_at->diffInSeconds(now()));
    }

    /**
     * Vérifie le code SMS saisi. Ne termine pas la demande : l'appelant le fait une fois l'action effectuée.
     */
    public function check(PhoneVerification $verification, string $code): void
    {
        $this->ensureOpen($verification);

        if ($verification->isVerified()) {
            return;
        }

        if ($verification->sms_code_hash === null) {
            throw new BusinessRuleException('Demandez d\'abord un code.', 'code');
        }

        if ($verification->attempts >= self::MAX_ATTEMPTS) {
            throw new BusinessRuleException('Trop d\'essais. Demandez un nouveau code.', 'code');
        }

        $verification->increment('attempts');

        if (! Hash::check(preg_replace('/\D+/', '', $code), $verification->sms_code_hash)) {
            $left = self::MAX_ATTEMPTS - $verification->attempts;
            throw new BusinessRuleException($left > 0 ? "Code incorrect. Encore {$left} essai(s)." : 'Code incorrect. Demandez un nouveau code.', 'code');
        }

        $verification->forceFill(['verified_at' => now(), 'verified_via' => 'sms'])->save();
    }

    /**
     * Message WhatsApp reçu sur le numéro du support : s'il porte un code de vérification, prouve le numéro et renvoie
     * la réponse à faire ; null si le message ne concerne pas une vérification. La page de la personne constate la
     * vérification à sa prochaine lecture et termine l'action (création de la boutique, connexion…).
     */
    public function handleWhatsApp(string $from, ?string $text): ?string
    {
        if (! preg_match('/code\s+de\s+v[ée]rification\D{0,10}(\d{6})/iu', (string) $text, $m)) {
            return null;
        }
        $from = PhoneNumber::normalize($from) ?? $from;

        $candidates = PhoneVerification::where('whatsapp_code', $m[1])->pending()->latest('id')->get();
        $verification = $candidates->first(fn (PhoneVerification $v) => PhoneNumber::sameNumber($v->phone, $from)) ?? $candidates->first();

        if ($verification === null) {
            return "Ce code n'est plus valable (il expire au bout de ".self::TTL_MINUTES.' minutes). Recommencez depuis la page Jibiat Marchand.';
        }
        // Le WhatsApp de l'expéditeur peut encore porter l'ancien numéro ivoirien à 8 chiffres
        if (! PhoneNumber::sameNumber($verification->phone, $from)) {
            Log::info('Code de vérification envoyé depuis un autre numéro', ['expected' => $verification->phone, 'from' => $from]);

            return 'Ce code a été demandé pour le numéro '.PhoneNumber::display($verification->phone)
                .', mais votre message arrive du numéro '.PhoneNumber::display($from)
                .'. Envoyez-le depuis le WhatsApp du '.PhoneNumber::display($verification->phone).'.';
        }

        if (! $verification->isVerified()) {
            $verification->forceFill(['verified_at' => now(), 'verified_via' => 'whatsapp'])->save();
        }

        return match ($verification->purpose) {
            VerificationPurpose::Signup => '✅ Numéro vérifié ! Retournez sur la page Jibiat Marchand : votre espace marchand s\'ouvre.',
            VerificationPurpose::Invitation => '✅ Numéro vérifié ! Retournez sur la page : vous rejoignez l\'équipe de '.($verification->store?->name ?? 'la boutique').'.',
            VerificationPurpose::PasswordReset => $verification->user_id
                ? '✅ Numéro vérifié. Retournez sur la page pour choisir votre nouveau mot de passe.'
                : 'Aucun compte Jibiat Marchand n\'est associé à ce numéro.',
            VerificationPurpose::ConsoleLogin, VerificationPurpose::Login => $verification->user_id
                ? '✅ Numéro vérifié. Retournez sur la page : vous y êtes connecté.'
                : 'Aucun compte Jibiat Marchand n\'est associé à ce numéro.',
        };
    }

    public function complete(PhoneVerification $verification): void
    {
        $verification->forceFill(['completed_at' => now()])->save();
    }

    private function ensureOpen(PhoneVerification $verification): void
    {
        if ($verification->completed_at !== null || $verification->isExpired()) {
            throw new BusinessRuleException('Cette demande a expiré. Recommencez.');
        }
    }

    private function smsText(PhoneVerification $verification, string $code): string
    {
        $what = match ($verification->purpose) {
            VerificationPurpose::Signup => 'pour créer votre espace marchand',
            VerificationPurpose::Invitation => 'pour rejoindre l\'espace marchand '.$verification->store?->name,
            VerificationPurpose::PasswordReset => 'pour changer votre mot de passe',
            VerificationPurpose::ConsoleLogin => 'pour vous connecter à la console Jibiat Marchand',
            VerificationPurpose::Login => 'pour vous connecter',
        };

        return "{$code} est votre code Jibiat Marchand {$what}. Il expire dans ".self::TTL_MINUTES.' minutes. Ne le communiquez à personne.';
    }

    private function uniqueWhatsAppCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (PhoneVerification::where('whatsapp_code', $code)->pending()->exists());

        return $code;
    }
}
