<?php

namespace App\Services\Accounts;

use App\Models\User;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Validation en deux étapes de la console : après le mot de passe, le code à 6 chiffres de l'application
 * d'authentification (ou un code de secours, valable une fois). Plus aucun code n'est envoyé par e-mail ou SMS à un
 * administrateur qui l'a activée.
 */
class ConsoleTwoFactor
{
    public const ISSUER = 'Jibiat Marchand Console';

    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RECOVERY_CODES = 8;

    public static function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    /**
     * Nouveau secret à scanner : lien otpauth et QR code (image PNG en data URI).
     *
     * @return array{secret: string, uri: string, qr: string}
     */
    public function draft(User $user): array
    {
        $secret = Totp::secret();
        $uri = Totp::uri($secret, self::ISSUER, $user->phone);
        $qr = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'scale' => 6, 'imageTransparent' => false])))->render($uri);

        return ['secret' => $secret, 'uri' => $uri, 'qr' => $qr];
    }

    /**
     * Active (ou remplace) l'application si le code correspond au secret scanné ; renvoie les codes de secours à
     * noter, affichés une seule fois. null si le code est faux.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $secret, string $code): ?array
    {
        $step = Totp::match($secret, $code);
        if ($step === null) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map(self::fingerprint(...), $codes),
        ])->save();

        return $codes;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(self::fingerprint(...), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    /**
     * Code de l'application (pas encore utilisé) ou code de secours (rayé une fois utilisé).
     *
     * @return 'app'|'recovery'|null
     */
    public function check(User $user, string $code): ?string
    {
        if (! self::enabled($user)) {
            return null;
        }

        return DB::transaction(function () use ($user, $code) {
            $user = User::lockForUpdate()->find($user->id);
            $step = Totp::match((string) $user->two_factor_secret, $code);
            // Un code de l'application ne sert qu'une fois (pas plus récent que le dernier utilisé)
            if ($step !== null && ($user->two_factor_last_step === null || $step > $user->two_factor_last_step)) {
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return 'app';
            }
            $fingerprint = self::fingerprint($code);
            $codes = $user->two_factor_recovery_codes ?? [];
            $index = array_search($fingerprint, $codes, true);
            if ($index !== false) {
                array_splice($codes, $index, 1);
                $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

                return 'recovery';
            }

            return null;
        });
    }

    public function recoveryLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /** Étape « code de l'application » après le mot de passe : jeton remis au navigateur (10 min, 5 essais). */
    public function start(User $user): string
    {
        $token = Str::random(48);
        Cache::put($this->key($token), ['user_id' => $user->id, 'attempts' => 0], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * L'administrateur dont le code est juste, et comment ('app' ou 'recovery') ; null sinon.
     *
     * @return array{0: User, 1: string}|null
     */
    public function finish(string $token, string $code): ?array
    {
        $key = $this->key($token);
        $pending = Cache::get($key);
        if ($pending === null) {
            return null;
        }
        $user = User::find($pending['user_id']);
        $how = $user ? $this->check($user, $code) : null;
        if ($how === null) {
            $attempts = $pending['attempts'] + 1;
            $attempts >= self::MAX_ATTEMPTS ? Cache::forget($key) : Cache::put($key, ['attempts' => $attempts] + $pending, now()->addMinutes(self::TTL_MINUTES));

            return null;
        }
        Cache::forget($key);

        return [$user->fresh(), $how];
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        // Sans caractères faciles à confondre (0/o, 1/l/i)
        $pick = fn () => implode('', array_map(fn () => 'abcdefghjkmnpqrstuvwxyz23456789'[random_int(0, 30)], range(1, 5)));

        return array_map(fn () => $pick().'-'.$pick(), range(1, self::RECOVERY_CODES));
    }

    private static function fingerprint(string $code): string
    {
        return hash_hmac('sha256', strtolower(trim($code)), (string) config('app.key'));
    }

    private function key(string $token): string
    {
        return 'console-2fa:'.hash('sha256', $token);
    }
}
