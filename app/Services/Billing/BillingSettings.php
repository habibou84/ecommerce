<?php

namespace App\Services\Billing;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Réglages du paiement des abonnements, choisis dans la console : moyen de paiement actif, lien et QR code du compte
 * marchand Wave, validateur (e-mail, téléphone, code). Sans réglage enregistré, les variables du serveur s'appliquent.
 *
 * Les clés d'API des prestataires restent sur le serveur : un prestataire n'est proposé que si elles y sont.
 */
class BillingSettings
{
    public const PROVIDERS = [
        'wave_link' => 'Lien marchand Wave (validé à la main)',
        'wave' => 'Wave Business (API)',
        'cinetpay' => 'CinetPay',
        'paydunya' => 'PayDunya',
    ];

    // Identifiants nécessaires à chaque prestataire (noms des champs du pilote)
    private const CREDENTIALS = [
        'wave' => ['api_key'],
        'cinetpay' => ['apikey', 'site_id'],
        'paydunya' => ['master_key', 'private_key', 'token'],
    ];

    /** @var array<string, string|null>|null */
    private ?array $saved = null;

    public function provider(): string
    {
        $provider = $this->value('provider') ?? (string) config('platform.billing.provider');

        return array_key_exists($provider, self::PROVIDERS) ? $provider : 'wave_link';
    }

    public function manual(): bool
    {
        return $this->provider() === 'wave_link';
    }

    /**
     * Prestataires utilisables : le lien Wave toujours, les autres quand leurs clés sont sur le serveur.
     *
     * @return array<string, bool>
     */
    public function available(): array
    {
        $credentials = (array) config('platform.billing.credentials');

        return collect(self::PROVIDERS)->map(fn ($label, $provider) => $provider === 'wave_link'
            || collect(self::CREDENTIALS[$provider])->every(fn ($field) => filled($credentials[$field] ?? null)))->all();
    }

    public function waveLink(): ?string
    {
        return $this->value('wave_link') ?? (config('platform.billing.wave_link') ?: null);
    }

    public function qrUrl(): ?string
    {
        $path = $this->value('wave_qr');

        return $path ? $this->disk()->url($path) : null;
    }

    public function validatorPhone(): ?string
    {
        return $this->value('validator_phone') ?? (config('platform.billing.validator_phone') ?: null);
    }

    public function validatorEmail(): ?string
    {
        return $this->value('validator_email');
    }

    public function pinSet(): bool
    {
        return $this->value('validator_pin') !== null || filled(config('platform.billing.validator_pin'));
    }

    /**
     * Code du validateur : celui de la console (haché), sinon celui du serveur.
     */
    public function checkPin(string $code): bool
    {
        if (($hash = $this->value('validator_pin')) !== null) {
            return Hash::check($code, $hash);
        }
        $pin = (string) config('platform.billing.validator_pin');

        return $pin !== '' && hash_equals($pin, $code);
    }

    /**
     * Le paiement par lien Wave est prêt : un lien ou un QR code pour payer.
     */
    public function ready(): bool
    {
        return ! $this->manual() || $this->waveLink() !== null || $this->qrUrl() !== null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): void
    {
        foreach (['provider', 'wave_link', 'validator_phone', 'validator_email'] as $key) {
            if (array_key_exists($key, $data)) {
                PlatformSetting::put("billing.{$key}", filled($data[$key]) ? (string) $data[$key] : null);
            }
        }
        if (filled($data['validator_pin'] ?? null)) {
            PlatformSetting::put('billing.validator_pin', Hash::make((string) $data['validator_pin']));
        }
        $this->saved = null;
    }

    public function storeQr(UploadedFile $file): void
    {
        $this->removeQr();
        PlatformSetting::put('billing.wave_qr', $file->store('platform', ['disk' => config('filesystems.catalog_disk', 'public'), 'visibility' => 'public']));
        $this->saved = null;
    }

    public function removeQr(): void
    {
        if (($path = $this->value('wave_qr')) !== null) {
            $this->disk()->delete($path);
            PlatformSetting::put('billing.wave_qr', null);
            $this->saved = null;
        }
    }

    /**
     * Réglages pour la console (le code n'est jamais renvoyé).
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        $available = $this->available();

        return [
            'provider' => $this->provider(),
            'providers' => collect(self::PROVIDERS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label, 'available' => $available[$code]])->values(),
            'wave_link' => $this->waveLink(),
            'wave_qr_url' => $this->qrUrl(),
            'validator_phone' => $this->validatorPhone(),
            'validator_email' => $this->validatorEmail(),
            'pin_set' => $this->pinSet(),
            'mail_ready' => $this->mailReady(),
        ];
    }

    /**
     * Un serveur d'e-mail est configuré (le pilote « log » n'envoie rien).
     */
    public function mailReady(): bool
    {
        return config('mail.default') !== 'log';
    }

    private function value(string $key): ?string
    {
        $this->saved ??= PlatformSetting::group('billing');
        $value = $this->saved[$key] ?? null;

        return filled($value) ? $value : null;
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.catalog_disk', 'public'));
    }
}
