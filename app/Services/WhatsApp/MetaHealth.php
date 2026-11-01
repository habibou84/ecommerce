<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAccount;
use Throwable;

/**
 * « État chez Meta » du numéro officiel : revue du compte, vérification de l'entreprise, envois bloqués ou limités,
 * nom affiché, qualité et moyen de paiement, dits simplement avec la page de Meta où agir.
 *
 * Relu chez Meta toutes les heures (et à la demande) ; chaque lecture est tolérante : un champ que Meta ne donne
 * pas est simplement absent.
 */
class MetaHealth
{
    /** Relecture automatique au plus toutes les heures */
    public const MAX_AGE_MINUTES = 60;

    private const TIERS = ['TIER_50' => '50', 'TIER_250' => '250', 'TIER_1K' => '1 000', 'TIER_2K' => '2 000', 'TIER_10K' => '10 000', 'TIER_100K' => '100 000', 'TIER_UNLIMITED' => 'un nombre illimité de'];

    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /** Plus vieux qu'une heure (ou jamais lu) */
    public static function stale(WhatsappAccount $account): bool
    {
        return $account->meta_health_at === null || $account->meta_health_at->lt(now()->subMinutes(self::MAX_AGE_MINUTES));
    }

    /**
     * Relit l'état chez Meta et l'enregistre (avec l'état général et depuis quand il dure).
     */
    public function refresh(WhatsappAccount $account): void
    {
        if (! $account->phone_number_id || ! $account->access_token) {
            return;
        }
        $token = $account->access_token;
        $raw = [];
        if ($account->waba_id) {
            $waba = $this->read($account->waba_id, $token, 'account_review_status,business_verification_status');
            $raw['review'] = $waba['account_review_status'] ?? null;
            $raw['verification'] = $waba['business_verification_status'] ?? null;
            $health = $this->read($account->waba_id, $token, 'health_status')['health_status'] ?? null;
            if (is_array($health)) {
                $raw['can_send'] = $health['can_send_message'] ?? null;
                $raw['errors'] = [];
                foreach ($health['entities'] ?? [] as $entity) {
                    if (($entity['entity_type'] ?? null) === 'BUSINESS') {
                        $raw['business_id'] = (string) ($entity['id'] ?? '');
                    }
                    foreach ($entity['errors'] ?? [] as $e) {
                        $raw['errors'][] = [
                            'code' => (int) ($e['error_code'] ?? 0),
                            'description' => mb_substr((string) ($e['error_description'] ?? ''), 0, 300),
                            'solution' => mb_substr((string) ($e['possible_solution'] ?? ''), 0, 300),
                            'level' => $entity['can_send_message'] ?? null,
                        ];
                    }
                }
            }
        }
        $number = $this->read($account->phone_number_id, $token, 'quality_rating,messaging_limit_tier,name_status,verified_name');
        $raw['quality'] = $number['quality_rating'] ?? null;
        $raw['tier'] = $number['messaging_limit_tier'] ?? null;
        if (isset($number['name_status'])) {
            app(NumberStatus::class)->apply($account, $number);
        }

        // Rien de lisible chez Meta (jeton expiré…) : l'ancien état reste, on réessaie plus tard
        if (array_filter($raw, fn ($v) => $v !== null && $v !== []) === []) {
            $account->forceFill(['meta_health_at' => now()])->save();

            return;
        }

        $account->forceFill(['meta_health' => $raw, 'meta_health_at' => now()]);
        $state = self::present($account)['state'];
        $account->forceFill([
            'meta_state_since' => $state === $account->meta_state ? ($account->meta_state_since ?? now()) : now(),
            'meta_state' => $state,
        ])->save();
    }

    /**
     * L'état à afficher : état général, titre et une ligne par point vérifié.
     *
     * @return array{state: string, title: string, reason: ?string, lines: list<array{key: string, tone: string, title: string, detail: string, action: ?array{label: string, url: string}}>, checked_at: ?string, since: ?string}
     */
    public static function present(WhatsappAccount $account): array
    {
        $raw = $account->meta_health ?? [];
        $links = self::links($account, $raw['business_id'] ?? null);
        $lines = [];

        // Envois bloqués ou limités par Meta, avec la raison donnée par Meta
        foreach ($raw['errors'] ?? [] as $i => $e) {
            $blocked = ($e['level'] ?? $raw['can_send'] ?? null) === 'BLOCKED';
            [$title, $action] = self::explainHealth($e, $links);
            $lines[] = ['key' => 'health-'.$i, 'tone' => $blocked ? 'bad' : 'warn', 'title' => $title,
                'detail' => trim(($e['description'] ?? '').' '.($e['solution'] ?? '')), 'action' => $action];
        }
        if (($raw['can_send'] ?? null) === 'BLOCKED' && empty($raw['errors'])) {
            $lines[] = ['key' => 'health', 'tone' => 'bad', 'title' => 'Meta bloque vos messages', 'detail' => 'Ouvrez votre Gestionnaire WhatsApp chez Meta pour voir la raison.', 'action' => ['label' => 'Voir chez Meta', 'url' => $links['manager']]];
        }

        // Revue du compte WhatsApp Business
        $lines[] = match ($raw['review'] ?? null) {
            'APPROVED' => ['key' => 'review', 'tone' => 'ok', 'title' => 'Compte WhatsApp Business', 'detail' => 'Validé par Meta', 'action' => null],
            'PENDING' => ['key' => 'review', 'tone' => 'warn', 'title' => 'Compte en cours de revue chez Meta', 'detail' => 'Meta vérifie le compte, souvent en moins de 2 jours. Les messages peuvent attendre jusque-là.', 'action' => null],
            'REJECTED' => ['key' => 'review', 'tone' => 'bad', 'title' => 'Compte refusé par Meta', 'detail' => 'Meta juge que l\'activité ne respecte pas ses règles de commerce. Si c\'est une erreur, faites appel.', 'action' => ['label' => 'Faire appel chez Meta', 'url' => $links['support']]],
            default => null,
        };

        // Nom affiché
        $name = $account->verified_name ? "Nom affiché « {$account->verified_name} »" : 'Nom affiché';
        $lines[] = match ($account->name_status) {
            'APPROVED', 'AVAILABLE_WITHOUT_REVIEW' => ['key' => 'name', 'tone' => 'ok', 'title' => $name, 'detail' => 'Approuvé', 'action' => null],
            'PENDING_REVIEW' => ['key' => 'name', 'tone' => 'warn', 'title' => $name, 'detail' => 'En vérification chez Meta (1 à 2 jours) : aucun message ne part avant.', 'action' => ['label' => 'Voir le nom chez Meta', 'url' => $links['numbers']]],
            'DECLINED', 'EXPIRED' => ['key' => 'name', 'tone' => 'bad', 'title' => $name, 'detail' => 'Refusé par Meta. Choisissez le nom de la boutique, écrit comme sur sa page Facebook, et redemandez.', 'action' => ['label' => 'Changer le nom chez Meta', 'url' => $links['numbers']]],
            default => null,
        };

        // Moyen de paiement : Meta ne le dit qu'en refusant un envoi
        $lines[] = str_contains((string) $account->last_error, '(#131042)')
            ? ['key' => 'payment', 'tone' => 'warn', 'title' => 'Aucun moyen de paiement chez Meta', 'detail' => 'Les messages automatiques (confirmation, livreur en route, avis…) ne partent pas. Vos réponses aux clients, elles, partent.', 'action' => ['label' => 'Ajouter une carte chez Meta', 'url' => $links['payment']]]
            : ['key' => 'payment', 'tone' => 'ok', 'title' => 'Moyen de paiement', 'detail' => 'Aucun refus de paiement signalé par Meta', 'action' => null];

        // Qualité du numéro et nombre de clients par jour
        $tier = self::TIERS[$raw['tier'] ?? ''] ?? null;
        $perDay = $tier ? " · jusqu'à {$tier} clients par jour" : '';
        $lines[] = match ($raw['quality'] ?? null) {
            'GREEN' => ['key' => 'quality', 'tone' => 'ok', 'title' => 'Qualité du numéro', 'detail' => 'Bonne'.$perDay, 'action' => null],
            'YELLOW' => ['key' => 'quality', 'tone' => 'warn', 'title' => 'Qualité du numéro moyenne', 'detail' => 'Des clients bloquent ou signalent vos messages. Écrivez seulement à des clients qui vous connaissent'.$perDay.'.', 'action' => null],
            'RED' => ['key' => 'quality', 'tone' => 'bad', 'title' => 'Qualité du numéro basse', 'detail' => 'Beaucoup de clients bloquent ou signalent vos messages : Meta peut limiter le numéro. Arrêtez les relances quelques jours'.$perDay.'.', 'action' => null],
            default => $tier ? ['key' => 'quality', 'tone' => 'ok', 'title' => 'Envois par jour', 'detail' => "Jusqu'à {$tier} clients par jour", 'action' => null] : null,
        };

        // Vérification de l'entreprise : utile, pas obligatoire (sauf si Meta la demande, voir plus haut)
        $lines[] = match (strtolower((string) ($raw['verification'] ?? ''))) {
            'verified' => ['key' => 'verification', 'tone' => 'ok', 'title' => 'Entreprise vérifiée', 'detail' => 'Vérifiée par Meta', 'action' => null],
            'pending', 'pending_submission', 'pending_need_more_info' => ['key' => 'verification', 'tone' => 'info', 'title' => 'Vérification de l\'entreprise en cours', 'detail' => 'Meta examine vos documents.', 'action' => ['label' => 'Suivre chez Meta', 'url' => $links['verification']]],
            'rejected', 'failed' => ['key' => 'verification', 'tone' => 'warn', 'title' => 'Vérification de l\'entreprise refusée', 'detail' => 'Recommencez avec un document au nom exact de la boutique (registre de commerce, facture d\'électricité ou de téléphone).', 'action' => ['label' => 'Vérifier mon entreprise', 'url' => $links['verification']]],
            'not_verified', 'unverified' => ['key' => 'verification', 'tone' => 'info', 'title' => 'Entreprise non vérifiée', 'detail' => 'Pas obligatoire. Utile pour écrire à plus de clients par jour.', 'action' => ['label' => 'Vérifier mon entreprise', 'url' => $links['verification']]],
            default => null,
        };

        $lines = array_values(array_filter($lines));
        // Ce qui bloque d'abord
        $order = ['bad' => 0, 'warn' => 1, 'info' => 2, 'ok' => 3];
        usort($lines, fn ($a, $b) => $order[$a['tone']] <=> $order[$b['tone']]);

        $tones = array_column($lines, 'tone');
        $state = in_array('bad', $tones, true) ? 'blocked' : (in_array('warn', $tones, true) ? 'partial' : ($raw === [] ? 'unknown' : 'ok'));
        $first = $lines[0] ?? null;

        return [
            'state' => $state,
            'title' => ['blocked' => 'Bloqué', 'partial' => 'En partie bloqué', 'ok' => 'Messages envoyés ✓', 'unknown' => 'Pas encore vérifié'][$state],
            'reason' => in_array($state, ['blocked', 'partial'], true) ? $first['title'] : null,
            'lines' => $lines,
            'checked_at' => $account->meta_health_at?->toIso8601String(),
            'since' => $account->meta_state_since?->toIso8601String(),
        ];
    }

    /**
     * Pages de Meta où agir, pour ce compte quand Meta accepte l'identifiant dans l'adresse.
     *
     * @return array<string, string>
     */
    public static function links(WhatsappAccount $account, ?string $businessId = null): array
    {
        $waba = $account->waba_id ? '?waba_id='.rawurlencode($account->waba_id) : '';
        $business = $businessId ? '?business_id='.rawurlencode($businessId) : '';

        return [
            'manager' => 'https://business.facebook.com/wa/manage/home/'.$waba,
            'numbers' => 'https://business.facebook.com/wa/manage/phone-numbers/'.$waba,
            'payment' => 'https://business.facebook.com/billing_hub/payment_settings/'.$business,
            'verification' => 'https://business.facebook.com/settings/security/'.$business,
            'support' => 'https://business.facebook.com/business-support-home/',
        ];
    }

    /**
     * Titre français et bouton pour une erreur de « health_status ».
     *
     * @param  array{code: int, description: string}  $e
     * @param  array<string, string>  $links
     * @return array{0: string, 1: array{label: string, url: string}}
     */
    private static function explainHealth(array $e, array $links): array
    {
        $text = strtolower(($e['description'] ?? '').' '.($e['solution'] ?? ''));

        return match (true) {
            str_contains($text, 'payment') => ['Aucun moyen de paiement valide chez Meta', ['label' => 'Ajouter une carte chez Meta', 'url' => $links['payment']]],
            str_contains($text, 'verif') => ['Meta demande de vérifier votre entreprise', ['label' => 'Vérifier mon entreprise', 'url' => $links['verification']]],
            str_contains($text, 'display name') || str_contains($text, 'name') => ['Le nom affiché bloque les envois', ['label' => 'Voir le nom chez Meta', 'url' => $links['numbers']]],
            str_contains($text, 'policy') || str_contains($text, 'restrict') || str_contains($text, 'disabled') => ['Compte restreint par Meta', ['label' => 'Faire appel chez Meta', 'url' => $links['support']]],
            default => ['Meta limite vos messages', ['label' => 'Voir chez Meta', 'url' => $links['manager']]],
        };
    }

    /** @return array<string, mixed> */
    private function read(string $id, string $token, string $fields): array
    {
        try {
            return $this->cloud->graph('GET', $id, $token, ['fields' => $fields]);
        } catch (Throwable) {
            return [];
        }
    }
}
