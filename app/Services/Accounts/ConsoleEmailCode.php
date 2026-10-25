<?php

namespace App\Services\Accounts;

use App\Models\User;
use App\Notifications\ConsoleLoginCode;
use App\Services\Billing\BillingSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Code de connexion à la console envoyé par e-mail (après le mot de passe) : 6 chiffres, valable 10 minutes,
 * 5 essais. Gardé en cache sous l'empreinte d'un jeton remis au navigateur ; le code n'est conservé que haché.
 * Si l'e-mail ne peut pas partir, la connexion repasse par la preuve du numéro (WhatsApp ou SMS).
 */
class ConsoleEmailCode
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * Envoie un code à l'adresse de l'administrateur.
     *
     * @return array{token: string, email: string}|null null si aucune adresse ou si l'e-mail n'est pas parti
     */
    public function start(User $user): ?array
    {
        $mailer = $this->settings->mailer();
        if (blank($user->email) || $mailer === null) {
            return null;
        }
        $code = (string) random_int(100000, 999999);
        try {
            Notification::route('mail', $user->email)->notifyNow(new ConsoleLoginCode($code, self::TTL_MINUTES, $mailer['mailer'], $mailer['from']));
        } catch (Throwable $e) {
            Log::warning('Code de connexion à la console non envoyé par e-mail', ['user' => $user->id, 'error' => $e->getMessage()]);

            return null;
        }
        $token = Str::random(48);
        Cache::put($this->key($token), ['user_id' => $user->id, 'code' => Hash::make($code)], now()->addMinutes(self::TTL_MINUTES));
        Cache::put($this->key($token).':attempts', 0, now()->addMinutes(self::TTL_MINUTES));

        return ['token' => $token, 'email' => self::mask($user->email)];
    }

    /**
     * L'administrateur dont le code est juste ; null sinon (demande expirée, code faux, trop d'essais).
     * Un code juste ne sert qu'une fois.
     */
    public function verify(string $token, string $code): ?User
    {
        $key = $this->key($token);
        $entry = Cache::get($key);
        if ($entry === null) {
            return null;
        }
        // Essai compté avant la vérification (compteur atomique) : des demandes simultanées ne dépassent pas la limite
        if ((int) Cache::increment($key.':attempts') > self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return null;
        }
        if (! Hash::check(trim($code), $entry['code'])) {
            return null;
        }
        Cache::forget($key);
        Cache::forget($key.':attempts');

        return User::find($entry['user_id']);
    }

    /** « a•••@gmail.com » : assez pour reconnaître l'adresse, sans la dévoiler */
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }

    private function key(string $token): string
    {
        return 'console-login:'.hash('sha256', $token);
    }
}
