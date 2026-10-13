<?php

namespace App\Services\WhatsApp;

use App\Exceptions\BusinessRuleException;
use App\Models\Store;
use App\Models\WhatsappAccount;
use RuntimeException;

/**
 * Connexion du WhatsApp d'une boutique en un clic (inscription intégrée de Meta) : la fenêtre Facebook renvoie un
 * code et les identifiants du compte ; le serveur obtient le jeton de la boutique, abonne l'application de la
 * plateforme au compte (notifications), active le numéro et crée les modèles de messages.
 */
class EmbeddedSignup
{
    public function __construct(private readonly WhatsAppCloud $cloud, private readonly TemplateLibrary $templates) {}

    public static function enabled(): bool
    {
        return filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret')) && filled(config('services.meta.whatsapp_config_id'));
    }

    /**
     * @return array{account: WhatsappAccount, warnings: list<string>}
     */
    public function connect(Store $store, string $code, string $wabaId, ?string $phoneNumberId, bool $coexistence): array
    {
        if (! self::enabled()) {
            throw new BusinessRuleException('La connexion en un clic n\'est pas encore activée sur la plateforme.');
        }

        try {
            $token = (string) ($this->cloud->graph('GET', 'oauth/access_token', null, [
                'client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'), 'code' => $code,
            ])['access_token'] ?? '');
            if ($token === '') {
                throw new RuntimeException('Meta n\'a pas renvoyé de jeton.');
            }

            // Numéro choisi dans la fenêtre ; à défaut, le premier numéro du compte
            $phoneNumberId = $phoneNumberId ?: (string) ($this->cloud->graph('GET', $wabaId.'/phone_numbers', $token)['data'][0]['id'] ?? '');
            if ($phoneNumberId === '') {
                throw new RuntimeException('Aucun numéro trouvé sur ce compte WhatsApp.');
            }
            $this->cloud->graph('POST', $wabaId.'/subscribed_apps', $token);
            $number = $this->cloud->graph('GET', $phoneNumberId, $token, ['fields' => 'display_phone_number,verified_name']);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException('Connexion à WhatsApp impossible. '.$e->getMessage());
        }

        $account = WhatsappAccount::forStore($store->id)->first() ?? new WhatsappAccount(['store_id' => $store->id]);
        $warnings = [];
        $pin = $account->two_step_pin ?: (string) random_int(100000, 999999);

        // Numéro gardé dans l'application WhatsApp Business (coexistence) : il est déjà actif chez Meta
        if (! $coexistence) {
            try {
                $this->cloud->graph('POST', $phoneNumberId.'/register', $token, ['messaging_product' => 'whatsapp', 'pin' => $pin]);
            } catch (RuntimeException $e) {
                $warnings[] = 'Activation du numéro : '.$e->getMessage();
            }
        }

        $account->fill([
            'connection' => 'embedded',
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $wabaId,
            'display_phone' => mb_substr((string) ($number['display_phone_number'] ?? ''), 0, 20) ?: null,
            'access_token' => $token,
            'app_secret' => null,
            'two_step_pin' => $pin,
            'status' => 'active',
            'last_error' => $warnings[0] ?? null,
            // Tout est automatique par défaut ; le rapport du jour reste au choix de chacun (Statistiques)
            'automations' => ($account->automations ?? []) + array_fill_keys(array_keys(CustomerMessenger::EVENTS), true),
        ])->save();

        $warnings = [...$warnings, ...$this->templates->install($account)];

        return ['account' => $account->fresh(), 'warnings' => $warnings];
    }
}
