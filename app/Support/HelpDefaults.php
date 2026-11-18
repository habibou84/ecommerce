<?php

namespace App\Support;

/**
 * Fiches de départ du centre d'aide : les étapes essentielles (avec leur vidéo) et les questions de tous les jours.
 * Créées une fois ; ensuite elles se modifient dans la console (Centre d'aide), jamais écrasées par une mise à jour.
 */
class HelpDefaults
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $video = fn (int $n) => collect(StartSteps::all())->firstWhere('n', $n);
        $rows = [
            ['creer-mon-espace', 'demarrer', 'Comment créer mon espace marchand ?',
                'Écrivez « Inscription » sur le WhatsApp de Jibiat Marchand : vous donnez le nom de votre boutique et votre prénom, et votre boutique est créée en 2 minutes. L\'essai est gratuit.',
                ['Écrivez « Inscription » sur le WhatsApp de Jibiat Marchand', 'Répondez aux questions : nom de la boutique, votre prénom', 'Touchez le lien reçu : vous êtes dans votre boutique', 'Ou sur le site : « Créez votre espace marchand », puis vérifiez votre numéro par WhatsApp'],
                'inscription inscrire créer compte boutique ouvrir commencer essai gratuit', 1, null, null],
            ['installer-application', 'demarrer', 'Comment installer l\'application sur mon téléphone ?',
                'Sur Android : ouvrez votre boutique dans Chrome, touchez ⋮ puis « Installer l\'application ». Sur iPhone : dans Safari, touchez Partager puis « Sur l\'écran d\'accueil ». Sur ordinateur, rien à installer.',
                ['Android : dans Chrome, touchez ⋮ puis « Installer l\'application »', 'iPhone : dans Safari, touchez Partager puis « Sur l\'écran d\'accueil »', 'Ouvrez l\'application depuis son icône', 'Dans Mon compte, touchez « Activer les notifications » pour être prévenu à chaque commande'],
                'installer application téléphone android iphone icône écran accueil notification télécharger play store', 2, '/gestion/compte', 'Ouvrir Mon compte'],
            ['informations-boutique', 'demarrer', 'Comment changer le nom, le slogan ou le numéro de ma boutique ?',
                'Dans Réglages › Ma boutique : nom, slogan, numéro WhatsApp des clients, adresse. C\'est ce que vos clients voient sur la boutique, les messages et les reçus.',
                ['Ouvrez Réglages › Ma boutique', 'Changez le nom, le slogan, le numéro WhatsApp où vos clients vous écrivent', 'Touchez « Enregistrer »'],
                'nom boutique slogan numéro whatsapp adresse informations coordonnées modifier changer reçu', 3, '/gestion/parametres', 'Ouvrir Ma boutique'],
            ['prix-de-livraison', 'livraisons', 'Comment régler mes frais de livraison ?',
                'Indiquez votre prix habituel de livraison : il s\'ajoute tout seul à chaque commande. Vous pouvez ensuite donner un prix différent par commune ou par quartier.',
                ['Ouvrez Livraisons › Tarifs et zones', 'Indiquez votre prix habituel dans Abidjan (1 500 F proposé)', 'Pour un quartier plus cher ou moins cher : changez le prix dans une commande, l\'application propose de le retenir', 'Autres villes : indiquez le prix jusqu\'à la gare (expéditions)'],
                'frais prix livraison quartier commune zone tarif combien intérieur gare expédition', 4, '/gestion/livraisons/tarifs?depart=1', 'Indiquer mon prix'],
            ['jours-de-livraison', 'livraisons', 'Comment choisir mes jours et mon heure limite de livraison ?',
                'Choisissez l\'heure limite pour livrer le jour même et les jours où vous ne livrez pas : chaque commande reçoit toute seule la bonne date de livraison.',
                ['Ouvrez « Zones et jours de livraison » (Livraisons › Tarifs et zones)', 'Choisissez l\'heure limite : une commande passée avant est livrée le jour même', 'Touchez les jours sans livraison (le dimanche par exemple)', 'Ajoutez les dates de fermeture (jours fériés, congés)'],
                'jour heure limite date livraison dimanche férié fermeture demain aujourd\'hui', 5, '/gestion/livraisons/zones', 'Régler les jours'],
            ['ajouter-un-produit', 'produits', 'Comment ajouter un produit et son stock ?',
                'Dans Mes produits, touchez « + Produit » : photo, nom, prix, stock. Le stock baisse tout seul à chaque vente.',
                ['Ouvrez Produits › Mes produits et touchez « + Produit »', 'Ajoutez une photo, écrivez le nom et le prix', 'Tailles et couleurs : cochez « Plusieurs variantes »', 'Indiquez le stock, puis « Enregistrer »', 'Beaucoup de produits ? Touchez « Importer » pour envoyer un fichier Excel'],
                'produit article ajouter créer stock prix taille couleur variante importer excel catalogue marge', 6, '/gestion/catalogue/nouveau', 'Ajouter un produit'],
            ['photos-des-produits', 'produits', 'Comment mettre des photos sur mes produits ?',
                'Dans Mes produits, touchez « + Ajouter une photo » sur le produit. La première photo est la photo principale. Prenez-la à la lumière du jour, sur un fond uni.',
                ['Ouvrez Produits › Mes produits', 'Touchez « + Ajouter une photo » sur le produit, ou ouvrez sa fiche puis « Photo » ou « Galerie »', 'La première photo est celle que les clients voient en premier', 'Astuce : lumière du jour, fond uni, le produit en entier'],
                'photo image produit galerie appareil belle fond lumière', 7, '/gestion/catalogue', 'Ouvrir Mes produits'],
            ['ajouter-un-livreur', 'livraisons', 'Comment ajouter mes livreurs ou une entreprise de livraison ?',
                'Dans Livraisons › Mes livreurs, touchez « + Livreur » : il reçoit ses missions sur son téléphone. Vous pouvez aussi confier vos colis à une entreprise de livraison, dans « Entreprises de livraison ».',
                ['Ouvrez Livraisons › Mes livreurs et touchez « + Livreur »', 'Écrivez son nom et son numéro : il reçoit le lien de son application', 'Il accepte la mission, appelle le client et encaisse ; vous suivez tout', 'Ou ouvrez « Entreprises de livraison » et touchez « Connecter » sur l\'entreprise de votre choix'],
                'livreur coursier ajouter entreprise livraison jibiat mission moto connecter', 8, '/gestion/livraisons/livreurs', 'Ajouter un livreur'],
            ['connecter-whatsapp', 'whatsapp', 'Comment connecter mon numéro WhatsApp ?',
                'Dans WhatsApp, touchez « Connecter mon numéro » et suivez les étapes de Facebook. Ensuite la confirmation, le suivi et le reçu partent tout seuls à vos clients, et leurs messages arrivent dans l\'application.',
                ['Ouvrez WhatsApp et touchez « Connecter mon numéro »', 'Vérifiez le nom de votre boutique, puis continuez avec Facebook', 'Choisissez votre numéro WhatsApp Business et suivez les étapes jusqu\'au bout', 'C\'est fait : les messages de vos clients arrivent dans Conversations'],
                'whatsapp connecter numéro meta facebook business message automatique confirmation reçu suivi bloqué', 9, '/gestion/whatsapp', 'Connecter WhatsApp'],
            ['boutique-en-ligne', 'boutique', 'Comment ouvrir ma boutique en ligne et partager le lien ?',
                'Dans Boutique en ligne › Apparence : choisissez le thème, ajoutez votre logo, puis ouvrez la boutique. Partagez son lien sur WhatsApp, Facebook et TikTok : vos clients commandent seuls, jour et nuit.',
                ['Ouvrez Boutique en ligne › Apparence', 'Choisissez un thème, ajoutez votre logo et l\'image d\'accueil', 'Touchez « Ouvrir la boutique »', 'Touchez « Voir la boutique » et partagez le lien à vos clients'],
                'boutique en ligne site lien ouvrir fermer thème logo couleur apparence partager', 10, '/gestion/boutique-en-ligne', 'Préparer la boutique'],
            ['saisir-une-commande', 'commandes', 'Comment enregistrer une commande ?',
                'Touchez le bouton + puis collez le message du client : le numéro, le nom, le quartier et les articles se remplissent seuls. Vérifiez, puis « Créer la commande ».',
                ['Touchez le gros bouton + : « Nouvelle commande » s\'ouvre', 'Touchez « Coller le message du client » : la commande se remplit', 'Ou écrivez le numéro du client, choisissez les articles et le quartier', 'Touchez « Créer la commande »'],
                'commande nouvelle saisir enregistrer créer coller message client vente', 11, '/gestion/commandes/nouvelle', 'Nouvelle commande'],
            ['confirmer-une-commande', 'commandes', 'Comment confirmer une commande avec le client ?',
                'Dans « À confirmer », appelez ou écrivez au client, puis touchez « Confirmée ». S\'il ne répond pas : « Injoignable » ou « Rappeler dans 3 h ». C\'est le meilleur moyen d\'éviter les refus à la livraison.',
                ['Ouvrez Ventes › À confirmer', 'Touchez « Appeler » ou « WhatsApp » pour joindre le client', 'Il est d\'accord : « Confirmée »', 'Il ne répond pas : « Injoignable » ou « Rappeler dans 3 h »', 'Il ne veut plus : « Refusée » ; mauvais numéro : « Faux numéro »'],
                'confirmer confirmation appeler client injoignable rappeler refusée faux numéro annuler', null, '/gestion/confirmation', 'Ouvrir À confirmer'],
            ['faire-livrer', 'commandes', 'Comment confier une commande à un livreur ?',
                'Dans Commandes, touchez « Faire livrer » sur une commande confirmée et choisissez votre livreur ou votre entreprise de livraison. Vous suivez ensuite la livraison dans les onglets de Commandes.',
                ['Ouvrez Ventes › Commandes', 'Sur une commande confirmée, touchez « Faire livrer »', 'Choisissez le livreur ou l\'entreprise de livraison', 'Suivez la livraison dans les onglets « À ramasser », « En route » et « En retard »'],
                'livrer livraison confier livreur commande en cours suivre colis où route ramasser retard', null, '/gestion/commandes', 'Ouvrir Commandes'],
            ['argent-des-livreurs', 'argent', 'Comment recevoir l\'argent encaissé par mes livreurs ?',
                'Dans Caisse › Argent chez les livreurs, vous voyez ce que chaque livreur a encaissé et pas encore versé. Quand il vous remet l\'argent, touchez « Recevoir ».',
                ['Ouvrez Caisse › Argent chez les livreurs', 'Vérifiez le montant à côté du nom du livreur', 'Quand il vous remet l\'argent, touchez « Recevoir »', 'Le paiement mobile money d\'un client va directement sur votre compte marchand'],
                'argent caisse livreur encaisser recevoir versement espèces mobile money wave orange', null, '/gestion/caisse/livreurs', 'Ouvrir l\'argent chez les livreurs'],
            ['premier-live', 'live', 'Comment vendre en live ?',
                'Vous faites votre live sur TikTok, Jibiat Marchand enregistre les commandes. Sur l\'accueil, suivez « Votre live en 5 étapes ».',
                ['Sur l\'accueil, ouvrez « Votre live en 5 étapes »', 'Connectez votre numéro WhatsApp pour recevoir les commandes', 'Indiquez votre prix de livraison', 'Choisissez les articles que vous allez montrer', 'Mettez la carte à épingler sur votre live, puis lancez votre live sur TikTok'],
                'live tiktok direct vendre code réservation carte épingler premier', null, '/gestion/live', 'Préparer un live'],
            ['mot-de-passe-oublie', 'compte', 'J\'ai oublié mon mot de passe, comment me connecter ?',
                'Sur la page de connexion, touchez « Me connecter par WhatsApp » : un message à envoyer, rien à recopier. Ou touchez « Mot de passe oublié ? » pour en choisir un nouveau.',
                ['Ouvrez la page de connexion de votre boutique', 'Touchez « Me connecter par WhatsApp » et envoyez le message sans le modifier', 'Ou touchez « Mot de passe oublié ? » et choisissez un nouveau mot de passe', 'Pour le changer ensuite : Mon compte › Mot de passe'],
                'mot de passe oublié connexion connecter perdu code accès bloqué entrer', null, '/gestion/compte', 'Ouvrir Mon compte'],
            ['inviter-mon-equipe', 'compte', 'Comment donner un accès à mon équipe ?',
                'Dans Réglages › Équipe, invitez la personne et choisissez son rôle : elle ne voit que ce que son rôle permet.',
                ['Ouvrez Réglages › Équipe', 'Invitez la personne et choisissez son rôle', 'Envoyez-lui le lien d\'invitation par WhatsApp', 'Elle se connecte avec son propre numéro'],
                'équipe employé gérant vendeuse assistant accès rôle inviter collaborateur', null, '/gestion/equipe', 'Ouvrir Équipe'],
            ['alertes', 'commandes', 'Comment être prévenu quand une commande attend ?',
                'Dans Réglages › Alertes, choisissez quand vous voulez être prévenu : commande pas confirmée, sans livreur, pas ramassée, problème de livraison. Activez aussi les notifications dans Mon compte.',
                ['Ouvrez Réglages › Alertes', 'Vérifiez les heures et les délais des alertes', 'Coupez celles qui ne vous servent pas', 'Activez les notifications dans Mon compte pour les recevoir sur votre téléphone'],
                'alerte notification prévenir sonnerie rappel retard commande attend', null, '/gestion/alertes', 'Ouvrir Alertes'],
        ];

        return collect($rows)->map(function (array $r, int $i) use ($video) {
            $v = $r[6] !== null ? $video($r[6]) : null;

            return [
                'slug' => $r[0], 'category' => $r[1], 'title' => $r[2], 'summary' => $r[3], 'steps' => $r[4], 'keywords' => $r[5],
                'video_url' => $v['video'] ?? ($r[0] === 'premier-live' ? '/videos/live/comment-ca-marche-v.mp4' : null),
                'poster_url' => $v['poster'] ?? ($r[0] === 'premier-live' ? '/videos/live/comment-ca-marche-v.jpg' : null),
                'video_seconds' => $v['seconds'] ?? null,
                'screen' => $r[7], 'screen_label' => $r[8], 'sort' => ($i + 1) * 10, 'is_published' => true,
            ];
        })->all();
    }
}
