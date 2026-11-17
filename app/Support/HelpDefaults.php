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
                'Écris « Inscription » sur le WhatsApp de Jibiat Marchand : tu donnes le nom de ta boutique et ton prénom, et ta boutique est créée en 2 minutes. L\'essai est gratuit.',
                ['Écris « Inscription » sur le WhatsApp de Jibiat Marchand', 'Réponds aux questions : nom de la boutique, ton prénom', 'Touche le lien reçu : tu es dans ta boutique', 'Ou sur le site : « Créer mon espace marchand », puis vérifie ton numéro par WhatsApp'],
                'inscription inscrire créer compte boutique ouvrir commencer essai gratuit', 1, null, null],
            ['installer-application', 'demarrer', 'Comment installer l\'application sur mon téléphone ?',
                'Sur Android : ouvre ta boutique dans Chrome, touche ⋮ puis « Installer l\'application ». Sur iPhone : dans Safari, touche Partager puis « Sur l\'écran d\'accueil ». Sur ordinateur, rien à installer.',
                ['Android : dans Chrome, touche ⋮ puis « Installer l\'application »', 'iPhone : dans Safari, touche Partager puis « Sur l\'écran d\'accueil »', 'Ouvre l\'application depuis son icône', 'Dans Mon compte, touche « Activer les notifications » pour être prévenu à chaque commande'],
                'installer application téléphone android iphone icône écran accueil notification télécharger play store', 2, '/gestion/compte', 'Ouvrir Mon compte'],
            ['informations-boutique', 'demarrer', 'Comment changer le nom, le slogan ou le numéro de ma boutique ?',
                'Dans Réglages › Informations de l\'espace marchand : nom, slogan, numéro WhatsApp des clients, adresse. C\'est ce que tes clients voient sur la boutique, les messages et les reçus.',
                ['Ouvre Réglages › Informations de l\'espace marchand', 'Change le nom, le slogan, le numéro WhatsApp où tes clients t\'écrivent', 'Touche « Enregistrer »'],
                'nom boutique slogan numéro whatsapp adresse informations modifier changer reçu', 3, '/gestion/parametres', 'Ouvrir les informations'],
            ['prix-de-livraison', 'livraisons', 'Comment régler mes frais de livraison ?',
                'Indique ton prix habituel de livraison : il s\'ajoute tout seul à chaque commande. Tu peux ensuite donner un prix différent par commune ou par quartier.',
                ['Ouvre Livraisons › Tarifs de livraison', 'Indique ton prix habituel dans Abidjan (1 500 F proposé)', 'Pour un quartier plus cher ou moins cher : change le prix dans une commande, l\'application propose de le retenir', 'Autres villes : indique le prix jusqu\'à la gare (expéditions)'],
                'frais prix livraison quartier commune zone tarif combien intérieur gare expédition', 4, '/gestion/livraisons/tarifs?depart=1', 'Indiquer mon prix'],
            ['jours-de-livraison', 'livraisons', 'Comment choisir mes jours et mon heure limite de livraison ?',
                'Choisis l\'heure limite pour livrer le jour même et les jours où tu ne livres pas : chaque commande reçoit toute seule la bonne date de livraison.',
                ['Ouvre Livraisons › Tarifs de livraison, puis « Zones avancées »', 'Choisis l\'heure limite : une commande passée avant est livrée le jour même', 'Touche les jours sans livraison (le dimanche par exemple)', 'Ajoute les dates de fermeture (jours fériés, congés)'],
                'jour heure limite date livraison dimanche férié fermeture demain aujourd\'hui', 5, '/gestion/livraisons/zones', 'Régler les jours'],
            ['ajouter-un-produit', 'produits', 'Comment ajouter un produit et son stock ?',
                'Dans Mes produits, touche « + Produit » : nom, prix, stock. Tu peux aussi prendre une photo : l\'assistant écrit la fiche pour toi. Le stock baisse tout seul à chaque vente.',
                ['Ouvre Mes produits et touche « + Produit »', 'Écris le nom et le prix (le coût d\'achat te montre ta marge)', 'Tailles et couleurs : coche « Plusieurs variantes »', 'Indique le stock, puis « Enregistrer »', 'Beaucoup de produits ? Touche « Importer » pour envoyer un fichier Excel'],
                'produit article ajouter créer stock prix taille couleur variante importer excel catalogue marge', 6, '/gestion/catalogue/nouveau', 'Ajouter un produit'],
            ['photos-des-produits', 'produits', 'Comment mettre des photos sur mes produits ?',
                'Dans Mes produits, touche « + Ajouter une photo » sur le produit. La première photo est la photo principale. Prends-la à la lumière du jour, sur un fond uni.',
                ['Ouvre Mes produits', 'Touche « + Ajouter une photo » sur le produit, ou ouvre sa fiche puis « Photo » ou « Galerie »', 'La première photo est celle que les clients voient en premier', 'Astuce : lumière du jour, fond uni, le produit en entier'],
                'photo image produit galerie appareil belle fond lumière', 7, '/gestion/catalogue', 'Ouvrir Mes produits'],
            ['ajouter-un-livreur', 'livraisons', 'Comment ajouter mes livreurs ou une entreprise de livraison ?',
                'Dans Livraisons › Mes livreurs, touche « + Livreur » : il reçoit ses missions sur son téléphone. Tu peux aussi confier tes colis à une entreprise de livraison, dans « Entreprises de livraison ».',
                ['Ouvre Livraisons › Mes livreurs et touche « + Livreur »', 'Écris son nom et son numéro : il reçoit le lien de son application', 'Il accepte la mission, appelle le client et encaisse ; tu suis tout', 'Ou ouvre « Entreprises de livraison » et touche « Connecter » sur l\'entreprise de ton choix'],
                'livreur coursier ajouter entreprise livraison jibiat mission moto connecter', 8, '/gestion/livraisons/livreurs', 'Ajouter un livreur'],
            ['connecter-whatsapp', 'whatsapp', 'Comment connecter mon numéro WhatsApp ?',
                'Dans WhatsApp, touche « Connecter mon numéro sur Meta » et suis les étapes de Facebook. Ensuite la confirmation, le suivi et le reçu partent tout seuls à tes clients, et leurs messages arrivent dans l\'application.',
                ['Ouvre WhatsApp et touche « Connecter mon numéro sur Meta »', 'Vérifie le nom de ta boutique, puis « Continuer avec Facebook »', 'Choisis ton numéro WhatsApp Business et suis les étapes jusqu\'au bout', 'C\'est fait : les messages de tes clients arrivent dans Messages'],
                'whatsapp connecter numéro meta facebook business message automatique confirmation reçu suivi bloqué', 9, '/gestion/whatsapp', 'Connecter WhatsApp'],
            ['boutique-en-ligne', 'boutique', 'Comment ouvrir ma boutique en ligne et partager le lien ?',
                'Dans Boutique en ligne › Apparence : choisis le thème, ajoute ton logo, puis ouvre la boutique. Partage son lien sur WhatsApp, Facebook et TikTok : tes clients commandent seuls, jour et nuit.',
                ['Ouvre Boutique en ligne › Apparence', 'Choisis un thème, ajoute ton logo et l\'image d\'accueil', 'Touche « Ouvrir la boutique »', 'Touche « Voir la boutique » et partage le lien à tes clients'],
                'boutique en ligne site lien ouvrir fermer thème logo couleur apparence partager', 10, '/gestion/boutique-en-ligne', 'Préparer la boutique'],
            ['saisir-une-commande', 'commandes', 'Comment enregistrer une commande ?',
                'Touche le bouton + puis colle le message du client : le numéro, le nom, le quartier et les produits se remplissent tout seuls. Vérifie, puis « Créer la commande ».',
                ['Touche le gros bouton + : « Nouvelle commande » s\'ouvre', 'Touche « Coller le message du client » : la commande se remplit', 'Ou écris le numéro du client, choisis les produits et le quartier', 'Touche « Créer la commande »'],
                'commande nouvelle saisir enregistrer créer coller message client vente', 11, '/gestion/commandes/nouvelle', 'Nouvelle commande'],
            ['confirmer-une-commande', 'commandes', 'Comment confirmer une commande avec le client ?',
                'Dans « À confirmer », appelle ou écris au client, puis touche « Confirmée ». S\'il ne répond pas : « Injoignable » ou « Rappeler dans 3 h ». C\'est le meilleur moyen d\'éviter les refus à la livraison.',
                ['Ouvre « À confirmer »', 'Touche « Appeler » ou « WhatsApp » pour joindre le client', 'Il est d\'accord : « Confirmée »', 'Il ne répond pas : « Injoignable » ou « Rappeler dans 3 h »', 'Il ne veut plus : « Refusée » ; mauvais numéro : « Faux numéro »'],
                'confirmer confirmation appeler client injoignable rappeler refusée faux numéro annuler', null, '/gestion/confirmation', 'Ouvrir À confirmer'],
            ['faire-livrer', 'commandes', 'Comment confier une commande à un livreur ?',
                'Dans Commandes, touche « Faire livrer » sur une commande confirmée et choisis ton livreur ou ton entreprise de livraison. Tu suis la livraison dans Livraisons › En cours.',
                ['Ouvre Commandes', 'Sur une commande confirmée, touche « Faire livrer »', 'Choisis le livreur ou l\'entreprise de livraison', 'Suis la course dans Livraisons › En cours'],
                'livrer livraison confier livreur commande en cours suivre colis où', null, '/gestion/commandes', 'Ouvrir Commandes'],
            ['argent-des-livreurs', 'argent', 'Comment recevoir l\'argent encaissé par mes livreurs ?',
                'Dans Caisse › Argent des livreurs, tu vois ce que chaque livreur a encaissé et pas encore versé. Quand il te remet l\'argent, touche « Recevoir ».',
                ['Ouvre Caisse › Argent des livreurs', 'Vérifie le montant à côté du nom du livreur', 'Quand il te remet l\'argent, touche « Recevoir »', 'Le paiement mobile money d\'un client arrive directement dans ton espace marchand'],
                'argent caisse livreur encaisser recevoir versement espèces mobile money wave orange', null, '/gestion/caisse/livreurs', 'Ouvrir l\'argent des livreurs'],
            ['premier-live', 'live', 'Comment vendre en live ?',
                'Toi, tu fais ton live sur TikTok. Jibiat Marchand enregistre les commandes. Sur l\'accueil, touche « Faire un live » et suis les 5 étapes.',
                ['Sur l\'accueil, touche « Faire un live »', 'Connecte ton numéro WhatsApp pour recevoir les commandes', 'Indique ton prix de livraison', 'Choisis les articles que tu vas montrer', 'Mets la carte à épingler sur ton live, puis lance ton live sur TikTok'],
                'live tiktok direct vendre code réservation carte épingler premier', null, '/gestion/live', 'Ouvrir Live'],
            ['mot-de-passe-oublie', 'compte', 'J\'ai oublié mon mot de passe, comment me connecter ?',
                'Sur la page de connexion, touche « Me connecter par WhatsApp » : un message à envoyer, rien à recopier. Ou touche « Mot de passe oublié ? » pour en choisir un nouveau.',
                ['Ouvre la page de connexion de ta boutique', 'Touche « Me connecter par WhatsApp » et envoie le message sans le modifier', 'Ou touche « Mot de passe oublié ? » et choisis un nouveau mot de passe', 'Pour le changer ensuite : Mon compte › Mot de passe'],
                'mot de passe oublié connexion connecter perdu code accès bloqué entrer', null, '/gestion/compte', 'Ouvrir Mon compte'],
            ['inviter-mon-equipe', 'compte', 'Comment donner un accès à mon équipe ?',
                'Dans Réglages › Équipe, invite la personne et choisis son rôle : elle ne voit que ce que son rôle permet.',
                ['Ouvre Réglages › Équipe', 'Invite la personne et choisis son rôle', 'Envoie-lui le lien d\'invitation par WhatsApp', 'Elle se connecte avec son propre numéro'],
                'équipe employé gérant vendeuse assistant accès rôle inviter collaborateur', null, '/gestion/equipe', 'Ouvrir Équipe'],
            ['alertes', 'commandes', 'Comment être prévenu quand une commande attend ?',
                'Dans Réglages › Alertes, choisis quand tu veux être prévenu : commande pas confirmée, sans livreur, pas ramassée, problème de livraison. Active aussi les notifications dans Mon compte.',
                ['Ouvre Réglages › Alertes', 'Vérifie les heures et les délais des alertes', 'Coupe celles qui ne te servent pas', 'Active les notifications dans Mon compte pour les recevoir sur ton téléphone'],
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
