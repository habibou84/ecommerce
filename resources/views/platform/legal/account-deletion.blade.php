@extends('platform.legal.layout')

@section('title', 'Supprimer son compte · Jibiat Marchand')
@section('heading', 'Supprimer son compte Jibiat Marchand')

@section('body')
    <p>Chaque personne (marchand, membre d'une équipe, livreur) peut supprimer elle-même son compte, dans l'application Jibiat Marchand ou sur le site.</p>

    <h2>Dans l'application ou sur le site</h2>
    <ul>
        <li><strong>Marchand ou membre d'une équipe</strong> : connectez-vous, ouvrez le menu › <strong>Mon compte</strong> › <strong>Supprimer mon compte</strong>, puis saisissez votre mot de passe.</li>
        <li><strong>Livreur</strong> : dans l'espace livreur, onglet <strong>Compte</strong> › <strong>Supprimer mon compte</strong>, puis saisissez votre mot de passe.</li>
        <li><strong>Propriétaire d'une boutique</strong> : fermez d'abord la boutique (Réglages › Données et fermeture ; un export de vos données est proposé), puis supprimez votre compte.</li>
    </ul>

    <h2>Sans accès à votre compte</h2>
    <p>Écrivez à {{ config('legal.privacy_email') }} depuis l'adresse email de votre compte, ou envoyez-nous un message WhatsApp depuis le numéro de votre compte, en demandant la suppression. Nous la faisons sous 30 jours.</p>

    <h2>Ce qui est supprimé, ce qui est conservé</h2>
    <ul>
        <li><strong>Supprimés aussitôt</strong> : votre nom, votre numéro de téléphone, votre email, votre mot de passe, vos accès aux boutiques et l'inscription de vos téléphones aux notifications. Votre numéro redevient libre.</li>
        <li><strong>Conservés dans les boutiques, sans lien avec vous</strong> : les commandes, livraisons et mouvements de caisse enregistrés, nécessaires à leur comptabilité.</li>
        <li><strong>Conservées 10 ans</strong> : les factures d'abonnement (obligation comptable).</li>
    </ul>
@endsection
