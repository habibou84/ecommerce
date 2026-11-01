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
        return filled(WhatsAppMetaApp::id()) && filled(WhatsAppMetaApp::secret()) && filled(WhatsAppMetaApp::configId());
    }

    /**
     * Jeton du compte à partir du code de Facebook. Connexion par la page entière (téléphone) : l'adresse de retour
     * utilisée doit être redonnée à Meta.
     */
    public function exchange(WhatsAppCloud $cloud, string $code, ?string $redirectUri = null): string
    {
        $token = (string) ($cloud->graph('GET', 'oauth/access_token', null, array_filter([
            'client_id' => WhatsAppMetaApp::id(), 'client_secret' => WhatsAppMetaApp::secret(), 'code' => $code, 'redirect_uri' => $redirectUri,
        ]))['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Meta n\'a pas renvoyé de jeton.');
        }

        return $token;
    }

    /**
     * Compte WhatsApp choisi dans Facebook, retrouvé avec le jeton (connexion par la page entière : la fenêtre ne
     * l'a pas transmis).
     */
    public function findWaba(WhatsAppCloud $cloud, string $token): string
    {
        $scopes = $cloud->graph('GET', 'debug_token', null, [
            'input_token' => $token, 'access_token' => WhatsAppMetaApp::id().'|'.WhatsAppMetaApp::secret(),
        ])['data']['granular_scopes'] ?? [];
        foreach (['whatsapp_business_management', 'whatsapp_business_messaging'] as $scope) {
            $ids = collect($scopes)->firstWhere('scope', $scope)['target_ids'] ?? [];
            if ($ids !== []) {
                return (string) end($ids);
            }
        }

        throw new RuntimeException('Aucun compte WhatsApp n\'a été choisi dans Facebook.');
    }

    /**
     * @return array{account: WhatsappAccount, warnings: list<string>}
     */
    public function connect(Store $store, string $code, ?string $wabaId, ?string $phoneNumberId, bool $coexistence, ?string $redirectUri = null): array
    {
        if (! self::enabled()) {
            throw new BusinessRuleException('La connexion en un clic n\'est pas encore activée sur la plateforme.');
        }

        try {
            $token = $this->exchange($this->cloud, $code, $redirectUri);
            $wabaId = $wabaId ?: $this->findWaba($this->cloud, $token);

            // Numéro choisi dans la fenêtre ; à défaut, le premier numéro du compte
            $phoneNumberId = $phoneNumberId ?: (string) ($this->cloud->graph('GET', $wabaId.'/phone_numbers', $token)['data'][0]['id'] ?? '');
            if ($phoneNumberId === '') {
                throw new RuntimeException('Aucun numéro trouvé sur ce compte WhatsApp.');
            }
            WhatsAppMetaApp::subscribe($this->cloud, $wabaId, $token);
            $number = $this->cloud->graph('GET', $phoneNumberId, $token, ['fields' => 'display_phone_number,verified_name,name_status']);
        } catch (RuntimeException $e) {
            throw new BusinessRuleException('Connexion à WhatsApp impossible. '.$e->getMessage());
        }

        $account = WhatsappAccount::forStore($store->id)->first() ?? new WhatsappAccount(['store_id' => $store->id]);
        $warnings = [];
        $pin = $account->two_step_pin ?: (string) random_int(100000, 999999);

        // Numéro gardé dans l'application WhatsApp Business (coexistence), ou déjà sur l'API de Meta : il est déjà
        // actif, on ne l'active pas une seconde fois. Meta passe tout seul en coexistence quand le numéro est déjà
        // dans l'application WhatsApp Business : le serveur le voit à « platform_type ».
        if (! $coexistence && ! $this->alreadyOnCloud($phoneNumberId, $token)) {
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
            'verified_name' => mb_substr((string) ($number['verified_name'] ?? ''), 0, 120) ?: null,
            'name_status' => mb_substr((string) ($number['name_status'] ?? ''), 0, 30) ?: null,
            'access_token' => $token,
            'app_secret' => null,
            'two_step_pin' => $pin,
            'status' => 'active',
            'last_error' => $warnings[0] ?? null,
            // Tout est automatique par défaut ; le rapport du jour reste au choix de chacun (Statistiques)
            'automations' => ($account->automations ?? []) + array_fill_keys(array_keys(CustomerMessenger::EVENTS), true),
        ])->save();
        NumberStatus::checked($account);

        // Numéro américain proposé par Meta dans la fenêtre : rien ne part avant l'approbation du nom
        if (NumberStatus::metaProvided($account->display_phone)) {
            $warnings[] = 'Vous avez choisi un numéro américain fourni par Meta ('.$account->display_phone.'). Aucun message ne part avant que Meta approuve son nom, et vos clients verront un numéro étranger : reconnectez plutôt votre propre numéro (+225).';
        }
        $warnings = [...$warnings, ...$this->templates->install($account)];

        return ['account' => $account->fresh(), 'warnings' => $warnings];
    }

    /** Numéro déjà actif sur l'API de Meta (coexistence ou autre prestataire) ; en cas de doute, non */
    private function alreadyOnCloud(string $phoneNumberId, string $token): bool
    {
        try {
            return ($this->cloud->graph('GET', $phoneNumberId, $token, ['fields' => 'platform_type'])['platform_type'] ?? null) === 'CLOUD_API';
        } catch (RuntimeException) {
            return false;
        }
    }
}
