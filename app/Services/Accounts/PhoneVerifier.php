<?php

namespace App\Services\Accounts;

use App\Enums\VerificationPurpose;
use App\Exceptions\BusinessRuleException;
use App\Models\PhoneVerification;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jibiat\Socle\PhoneNumber;
use Jibiat\Socle\Sms\SmsException;
use Jibiat\Socle\Sms\SmsGateway;

/**
 * Vérification d'un numéro par code SMS. Le navigateur reçoit un jeton secret qui désigne la demande ;
 * le code envoyé par SMS prouve que la personne a le téléphone en main.
 *
 * Reprise de la vérification de Delivery App, sans la preuve par WhatsApp entrant : la plateforme n'a pas
 * encore de numéro WhatsApp Business (phase 6).
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

    public function __construct(private readonly SmsGateway $sms) {}

    /**
     * Ouvre une vérification et envoie le premier code.
     *
     * @param  array<string, mixed>|null  $payload  données à conserver jusqu'à la fin (chiffrées)
     * @return array{0: PhoneVerification, 1: string, 2: ?string} vérification, jeton du navigateur, code (démonstration)
     */
    public function start(VerificationPurpose $purpose, string $phone, ?Store $store = null, ?User $user = null, ?array $payload = null, ?string $ip = null): array
    {
        $phone = PhoneNumber::normalize($phone) ?? throw new BusinessRuleException('Numéro de téléphone invalide.', 'phone');

        if (! $this->smsAvailable($phone)) {
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
                'payload' => $payload,
                'ip' => $ip,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        return [$verification, $token, $this->sendSms($verification)];
    }

    public function find(string $token): ?PhoneVerification
    {
        return PhoneVerification::where('token_hash', PhoneVerification::hashToken($token))->first();
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

        // Mot de passe oublié pour un numéro sans compte : rien n'est envoyé, sans le révéler
        if ($verification->purpose === VerificationPurpose::PasswordReset && $verification->user_id === null) {
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
     * Vérifie le code saisi. Ne termine pas la demande : l'appelant le fait une fois l'action effectuée.
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

        $verification->forceFill(['verified_at' => now()])->save();
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
        };

        return "{$code} est votre code Jibiat Marchand {$what}. Il expire dans ".self::TTL_MINUTES.' minutes. Ne le communiquez à personne.';
    }
}
