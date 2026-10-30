<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAccount;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * État du numéro officiel chez Meta, dit simplement au marchand : vérification du nom affiché, numéro américain
 * fourni par Meta (à remplacer par son propre numéro) et erreurs de Meta expliquées en français.
 */
class NumberStatus
{
    /** Nom affiché qui laisse partir les messages */
    public const NAME_OK = ['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'];

    /** Erreurs de Meta les plus courantes : titre et marche à suivre */
    private const ERRORS = [
        131037 => ['Meta vérifie le nom affiché de votre numéro', 'Aucun message ne part tant que Meta n\'a pas approuvé ce nom, souvent en 1 à 2 jours. Le nom doit ressembler à celui de votre boutique (page Facebook, site). Suivez la vérification dans votre Gestionnaire WhatsApp chez Meta, rubrique « Numéros de téléphone ».'],
        131047 => ['Plus de 24 h depuis le dernier message du client', 'Meta n\'autorise un message libre que dans les 24 h qui suivent le dernier message du client. Au-delà, seul un modèle approuvé peut partir.'],
        132001 => ['Modèle de message introuvable chez Meta', 'Le modèle n\'est pas encore approuvé ou a été supprimé. Attendez la fin de la vérification, ou reconnectez votre numéro avec Facebook pour recréer les modèles.'],
        131031 => ['Compte WhatsApp bloqué par Meta', 'Ouvrez votre Gestionnaire WhatsApp chez Meta pour connaître la raison et faire appel.'],
        133010 => ['Numéro pas encore activé chez Meta', 'Reconnectez votre numéro avec Facebook pour terminer son activation.'],
        131042 => ['Moyen de paiement manquant chez Meta', 'Les modèles de messages sont facturés par Meta : ajoutez une carte dans votre Gestionnaire WhatsApp, rubrique « Paiements ».'],
        131026 => ['Ce client ne peut pas recevoir le message', 'Son numéro n\'a pas WhatsApp, ou son application est trop ancienne.'],
        130429 => ['Trop de messages en peu de temps', 'Meta ralentit les envois un moment ; ils reprennent tout seuls.'],
        131056 => ['Trop de messages au même client', 'Meta ralentit les envois vers ce numéro un moment ; ils reprennent tout seuls.'],
        190 => ['Accès à Meta expiré', 'Reconnectez votre numéro avec Facebook (menu « … » du numéro).'],
    ];

    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /**
     * Relit chez Meta le nom affiché et son état tant qu'il n'est pas approuvé (au plus toutes les 10 minutes).
     */
    public function refresh(WhatsappAccount $account): void
    {
        if (in_array($account->name_status, self::NAME_OK, true) || ! $account->phone_number_id || ! $account->access_token) {
            return;
        }
        // Connexion avancée : relu seulement quand Meta a refusé un envoi pour le nom
        if (! $account->isEmbedded() && self::code($account->last_error) !== 131037) {
            return;
        }
        if (! Cache::add(self::key($account), true, now()->addMinutes(10))) {
            return;
        }
        try {
            $number = $this->cloud->graph('GET', $account->phone_number_id, $account->access_token, ['fields' => 'verified_name,name_status']);
        } catch (Throwable) {
            return;
        }
        $this->apply($account, $number);
    }

    /**
     * Enregistre le nom et son état lus chez Meta ; un nom approuvé efface l'erreur « nom à approuver ».
     *
     * @param  array<string, mixed>  $number
     */
    public function apply(WhatsappAccount $account, array $number): void
    {
        $account->forceFill(array_filter([
            'verified_name' => mb_substr((string) ($number['verified_name'] ?? ''), 0, 120) ?: null,
            'name_status' => mb_substr((string) ($number['name_status'] ?? ''), 0, 30) ?: null,
        ]));
        if (in_array($account->name_status, self::NAME_OK, true) && self::code($account->last_error) === 131037) {
            $account->last_error = null;
        }
        $account->save();
    }

    /**
     * Numéro américain fourni par Meta pendant la connexion, et non celui du marchand.
     */
    public static function metaProvided(?string $phone, ?string $lastError = null): bool
    {
        return self::code($lastError) === 131037 || str_starts_with(preg_replace('/[^\d+]/', '', (string) $phone), '+1');
    }

    /**
     * Erreur de Meta expliquée : titre et marche à suivre, ou null si elle n'est pas connue.
     *
     * @return array{title: string, help: string}|null
     */
    public static function explain(?string $error): ?array
    {
        $code = self::code($error);
        if ($code === null && $error !== null && stripos($error, 'access token') !== false) {
            $code = 190;
        }
        $known = $code !== null ? (self::ERRORS[$code] ?? null) : null;

        return $known ? ['title' => $known[0], 'help' => $known[1]] : null;
    }

    /**
     * Nom et état viennent d'être lus chez Meta (connexion) : pas de nouvelle lecture avant 10 minutes.
     */
    public static function checked(WhatsappAccount $account): void
    {
        Cache::put(self::key($account), true, now()->addMinutes(10));
    }

    private static function key(WhatsappAccount $account): string
    {
        return 'whatsapp-name:'.$account->id;
    }

    private static function code(?string $error): ?int
    {
        return $error !== null && preg_match('/\(#(\d+)\)/', $error, $m) ? (int) $m[1] : null;
    }
}
