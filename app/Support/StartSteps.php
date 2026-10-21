<?php

namespace App\Support;

/**
 * « Les étapes essentielles pour commencer » : une courte vidéo par étape (public/videos/etapes), sur la page
 * « Bien démarrer » du site et dans le guide de démarrage de l'application (même ordre que ce guide).
 */
class StartSteps
{
    /**
     * @return list<array{n: int, guide: ?string, title: string, text: string, seconds: int, video: string, poster: string}>
     */
    public static function all(): array
    {
        return collect([
            [null, 'Créer votre espace marchand', 'Nom de la boutique, votre numéro, le code reçu par SMS : l\'essai gratuit démarre tout de suite.', 25],
            ['mobile_app', 'Installer l\'application sur votre téléphone', 'Sur ordinateur, rien à installer. Sur Android : Chrome › ⋮ › « Installer l\'application ». Sur iPhone : Safari › Partager › « Sur l\'écran d\'accueil ». Puis activez les notifications.', 39],
            ['store_info', 'Compléter les informations de la boutique', 'Nom, slogan, numéro WhatsApp : ce que vos clients voient sur la boutique, les messages et les reçus.', 21],
            ['delivery_zones', 'Régler les frais de livraison par quartier', 'Le bon prix de livraison s\'ajoute tout seul à chaque commande, selon le quartier du client.', 20],
            ['delivery_days', 'Régler les jours et l\'heure limite de livraison', 'Chaque commande reçoit sa date : le jour même avant l\'heure limite, sinon le jour suivant.', 21],
            ['products', 'Ajouter vos produits et leur stock', 'Prix, coût d\'achat, tailles et couleurs, quantités. Le stock baisse tout seul à chaque vente.', 26],
            ['photos', 'Mettre des photos sur vos produits', 'Une belle photo fait vendre sur la boutique en ligne et sur les réseaux.', 20],
            ['deliverers', 'Choisir qui livre', 'Vos livreurs reçoivent leurs missions sur leur téléphone, ou connectez une entreprise de livraison en un clic.', 26],
            ['whatsapp', 'Connecter votre WhatsApp', 'Confirmation, suivi et reçu partent tout seuls ; les messages des clients arrivent dans l\'application.', 27],
            ['online_store', 'Ouvrir votre boutique en ligne', 'Choisissez son apparence, ouvrez-la et partagez le lien : vos clients commandent seuls, jour et nuit.', 26],
            ['first_order', 'Saisir votre première commande', 'Collez le message du client, confirmez, confiez au livreur, encaissez : tout le parcours.', 34],
        ])->map(fn (array $s, int $i) => [
            'n' => $i + 1,
            'guide' => $s[0],
            'title' => $s[1],
            'text' => $s[2],
            'seconds' => $s[3],
            'video' => sprintf('/videos/etapes/etape-%02d.mp4', $i + 1),
            'poster' => sprintf('/videos/etapes/etape-%02d.jpg', $i + 1),
        ])->all();
    }

    /**
     * Vidéo d'une étape du guide de démarrage (null si l'étape n'en a pas).
     *
     * @return array{video: string, poster: string, seconds: int}|null
     */
    public static function forGuide(string $key): ?array
    {
        $step = collect(self::all())->firstWhere('guide', $key);

        return $step ? ['video' => $step['video'], 'poster' => $step['poster'], 'seconds' => $step['seconds']] : null;
    }
}
