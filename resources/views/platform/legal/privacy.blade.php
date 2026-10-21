@extends('platform.legal.layout')

@section('title', 'Politique de confidentialité · Jibiat Marchand')
@section('heading', 'Politique de confidentialité')

@section('body')
    <p>Cette politique explique comment {{ config('legal.company') }} (« nous ») traite les données personnelles, conformément à la loi ivoirienne n° 2013-450 du 19 juin 2013 relative à la protection des données à caractère personnel, sous le contrôle de l'ARTCI.</p>

    <h2>1. Qui est responsable ?</h2>
    <ul>
        <li><strong>Données des marchands et de leurs équipes</strong> (compte, abonnement) : nous en sommes responsables.</li>
        <li><strong>Données des clients des boutiques</strong> (commandes, adresses de livraison) : chaque marchand en est responsable ; nous les traitons pour son compte, uniquement pour fournir le Service.</li>
    </ul>

    <h2>2. Données traitées et finalités</h2>
    <ul>
        <li>Compte : nom, numéro de téléphone, email facultatif, mot de passe chiffré, journal de connexion — pour gérer l'accès et la sécurité.</li>
        <li>Abonnement : offre, factures, références de paiement (jamais les numéros de carte) — pour la facturation et nos obligations comptables.</li>
        <li>Clients des boutiques : nom, téléphone, quartier et adresse, articles commandés, historique de livraison — pour traiter, livrer et suivre les commandes, et calculer un indicateur de risque propre à chaque espace marchand.</li>
        <li>Livreurs : nom, téléphone, missions, encaissements, preuve de livraison (photo) — pour organiser et payer les livraisons.</li>
        <li>Mesure d'audience des boutiques : si le marchand les active, pixels Meta, TikTok et Google Analytics, et envoi des achats à Meta avec des données hachées — pour mesurer ses publicités.</li>
    </ul>

    <h2>3. Destinataires</h2>
    <p>Les données ne sont jamais vendues. Elles sont communiquées seulement à : l'équipe de l'espace marchand concerné ; les entreprises de livraison et prestataires de paiement choisis par le marchand ; nos sous-traitants techniques (hébergement, envoi de SMS, WhatsApp de Meta, supervision des erreurs), tenus à la confidentialité. Certains de ces prestataires peuvent être situés hors de Côte d'Ivoire ; ces transferts sont encadrés conformément à la loi.</p>

    <h2>4. Durées de conservation</h2>
    <ul>
        <li>Compte et espace marchand : pendant toute la durée d'utilisation, puis 90 jours après la fermeture.</li>
        <li>Factures et pièces comptables : 10 ans.</li>
        <li>Codes de vérification SMS : quelques heures ; journaux techniques : 12 mois au plus.</li>
    </ul>

    <h2>5. Sécurité</h2>
    <p>Accès chiffré (https), mots de passe et clés des prestataires chiffrés, cloisonnement strict entre espaces marchands, sauvegardes quotidiennes, accès de notre équipe limité, journalisé et soumis à une double vérification.</p>

    <h2>6. Vos droits</h2>
    <p>Vous pouvez accéder à vos données, les faire rectifier ou supprimer, vous opposer à un traitement pour un motif légitime, et en demander la portabilité. Pour les données collectées par une boutique, adressez-vous d'abord à cette boutique. Vous pouvez aussi supprimer vous-même votre compte (<a href="{{ route('legal.account-deletion') }}">comment faire</a>). Contact : {{ config('legal.privacy_email') }}. Vous pouvez aussi saisir l'ARTCI (www.artci.ci).</p>

    <h2>7. Cookies</h2>
    <p>Les boutiques utilisent un cookie de panier et un cookie de session pour la gestion, indispensables au fonctionnement. Les pixels publicitaires ne sont présents que si le marchand les a activés.</p>
@endsection
