<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappAccount;
use Throwable;

/**
 * Modèles de messages prêts à l'emploi, créés chez Meta pour un numéro connecté en un clic : un par message
 * automatique, en français, variables dans l'ordre de CustomerMessenger::EVENTS. Meta les vérifie (en général en
 * quelques minutes) et l'état est suivi par les notifications « message_template_status_update ».
 */
class TemplateLibrary
{
    /**
     * Meta refuse une variable en tout début ou toute fin de texte : chaque texte se termine par une phrase.
     */
    public const TEMPLATES = [
        'order_confirmation' => [
            'name' => 'jm_confirmation_commande', 'category' => 'UTILITY',
            'body' => 'Bonjour {{1}}, merci pour votre commande {{2}} d\'un montant de {{3}}. Confirmez-vous la commande ? Répondez avec un des boutons ci-dessous.',
            'example' => ['Awa', '#1001', '15 000 F'],
            'buttons' => ['Confirmer', 'Annuler'],
        ],
        'order_shipped' => [
            'name' => 'jm_commande_en_route', 'category' => 'UTILITY',
            'body' => 'Bonjour {{1}}, votre commande {{2}} est en route ! Suivez-la ici : {{3}} . Merci de rester joignable.',
            'example' => ['Awa', '#1001', 'https://boutique.jibiat-marchand.com/suivi/abc'],
        ],
        'order_delivered' => [
            'name' => 'jm_commande_livree', 'category' => 'UTILITY',
            'body' => 'Bonjour {{1}}, votre commande {{2}} a bien été livrée. Merci pour votre confiance et à bientôt !',
            'example' => ['Awa', '#1001'],
        ],
        'review_request' => [
            'name' => 'jm_demande_avis', 'category' => 'MARKETING',
            'body' => 'Bonjour {{1}}, merci d\'avoir commandé chez {{2}} ! Votre avis sur les articles reçus nous aide beaucoup : {{3}} . Merci !',
            'example' => ['Awa', 'Awa Mode', 'https://boutique.jibiat-marchand.com/avis/abc'],
        ],
        'payment_link' => [
            'name' => 'jm_lien_paiement', 'category' => 'UTILITY',
            'body' => 'Bonjour {{1}}, voici le lien pour régler votre commande {{2}} ({{3}}) : {{4}} . Merci !',
            'example' => ['Awa', '#1001', '15 000 F', 'https://boutique.jibiat-marchand.com/payer/abc'],
        ],
        'cart_recovery' => [
            'name' => 'jm_panier_en_attente', 'category' => 'MARKETING',
            'body' => 'Bonjour {{1}}, vous avez laissé des articles dans votre panier chez {{2}}. Retrouvez-le ici : {{3}} . À bientôt !',
            'example' => ['Awa', 'Awa Mode', 'https://boutique.jibiat-marchand.com/panier/abc'],
        ],
        'daily_report' => [
            'name' => 'jm_rapport_du_jour', 'category' => 'UTILITY',
            'body' => 'Rapport de {{1}} du {{2}} : {{3}} commande(s) reçue(s), {{4}} livré, {{5}} à confirmer, {{6}} à faire livrer. Bonne journée !',
            'example' => ['Awa Mode', '13/10', '12', '180 000 F', '3', '4'],
        ],
    ];

    public function __construct(private readonly WhatsAppCloud $cloud) {}

    /**
     * Crée les modèles manquants ; un modèle refusé à la création n'empêche pas les autres.
     *
     * @return list<string> erreurs, par modèle
     */
    public function install(WhatsappAccount $account): array
    {
        $templates = $account->templates ?? [];
        $status = $account->template_status ?? [];
        $errors = [];

        foreach (self::TEMPLATES as $event => $t) {
            $components = [['type' => 'BODY', 'text' => $t['body'], 'example' => ['body_text' => [$t['example']]]]];
            if (! empty($t['buttons'])) {
                $components[] = ['type' => 'BUTTONS', 'buttons' => array_map(fn ($b) => ['type' => 'QUICK_REPLY', 'text' => $b], $t['buttons'])];
            }
            try {
                $created = $this->cloud->graph('POST', $account->waba_id.'/message_templates', $account->access_token, [
                    'name' => $t['name'], 'language' => 'fr', 'category' => $t['category'], 'components' => $components,
                ]);
                $status[$event] = (string) ($created['status'] ?? 'PENDING');
            } catch (Throwable $e) {
                // Déjà créé (nouvelle connexion du même compte) : on le garde
                if (! str_contains(mb_strtolower($e->getMessage()), 'already exists') && ! str_contains($e->getMessage(), 'existe déjà')) {
                    $errors[] = $t['name'].' : '.$e->getMessage();
                    $status[$event] = 'ERROR';

                    continue;
                }
                $status[$event] ??= 'PENDING';
            }
            $templates[$event] = $t['name'];
        }

        $account->forceFill(['templates' => $templates, 'template_status' => $status])->save();

        return $errors;
    }

    /**
     * Notification de Meta : modèle approuvé, refusé, mis en pause…
     */
    public function updateStatus(WhatsappAccount $account, string $name, string $status): void
    {
        $event = array_search($name, $account->templates ?? [], true);
        if ($event !== false) {
            $account->forceFill(['template_status' => [$event => strtoupper($status)] + ($account->template_status ?? [])])->save();
        }
    }
}
