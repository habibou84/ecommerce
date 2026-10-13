@extends('platform.legal.layout')

@section('title', 'Conditions générales d\'utilisation et de vente · Jibiat Marchand')
@section('heading', 'Conditions générales d\'utilisation et de vente')

@section('body')
    <h2>1. Objet</h2>
    <p>Jibiat Marchand (le « Service ») est une plateforme en ligne qui permet à un commerçant (le « Marchand ») de créer une boutique en ligne, de gérer ses produits, son stock, ses commandes et ses clients, de livrer avec ses propres livreurs ou avec des entreprises de livraison partenaires, et d'encaisser des paiements en ligne sur ses propres comptes. Le Service est édité par {{ config('legal.company') }} (l'« Éditeur »).</p>
    <p>Les présentes conditions s'appliquent à tout Marchand et à chaque membre de son équipe. Créer une boutique vaut acceptation de ces conditions.</p>

    <h2>2. Compte et boutique</h2>
    <ul>
        <li>Le Marchand ouvre un compte avec un numéro de téléphone vérifié par SMS. Il garde son mot de passe secret et reste responsable des actions faites depuis son compte et ceux de son équipe.</li>
        <li>Les informations fournies (nom, numéro, adresse de la boutique) doivent être exactes. L'adresse {slug}.{{ config('platform.domain') }} ne doit pas porter atteinte aux droits d'un tiers.</li>
        <li>Le Marchand est seul vendeur des produits proposés sur sa boutique : il fixe ses prix, ses conditions de vente, de livraison et de retour, et répond de ses obligations envers ses clients (information, garantie, facturation, fiscalité).</li>
    </ul>

    <h2>3. Usages interdits</h2>
    <p>Il est interdit de vendre des produits illicites, contrefaits ou dangereux, de diffuser des contenus trompeurs ou contraires à l'ordre public, d'envoyer des messages non sollicités, de tenter d'accéder aux données d'une autre boutique ou de perturber le Service. L'Éditeur peut suspendre une boutique qui enfreint ces règles, après avoir, sauf urgence, invité le Marchand à corriger la situation.</p>

    <h2>4. Offres, prix et paiement</h2>
    <ul>
        <li>Le Service est proposé en plusieurs offres (Gratuit, Starter, Pro, Business) dont le contenu, les limites et les prix sont indiqués sur la page Abonnement de la gestion. Les prix sont en francs CFA (XOF).</li>
        <li>Une boutique nouvellement créée bénéficie d'un essai gratuit de {{ config('platform.trial_days') }} jours de l'offre {{ ucfirst(config('platform.trial_plan')) }}, sans engagement ni moyen de paiement.</li>
        <li>L'abonnement est payé d'avance, au mois ou à l'année, par mobile money ou carte via notre prestataire de paiement. Une facture est disponible dans la gestion après chaque paiement.</li>
        <li>Sans renouvellement à l'échéance, la boutique dispose d'un délai de grâce de {{ config('platform.billing.grace_days') }} jours, puis repasse à l'offre gratuite. Aucune donnée n'est supprimée ; les limites de l'offre gratuite s'appliquent aux nouveaux ajouts.</li>
        <li>Le Marchand peut demander à tout moment le retour à l'offre gratuite à la fin de la période payée. Les sommes payées pour une période commencée ne sont pas remboursées, sauf manquement de l'Éditeur.</li>
        <li>L'Éditeur peut modifier ses prix ; le nouveau prix s'applique à la période suivante, après information du Marchand au moins 30 jours à l'avance.</li>
    </ul>

    <h2>5. Paiements des clients du Marchand</h2>
    <p>Les paiements en ligne des clients sont encaissés directement sur les comptes du Marchand chez les prestataires qu'il a choisis (Wave, CinetPay, PayDunya…). L'Éditeur ne détient jamais ces fonds. Le Marchand reste lié par les conditions de ses prestataires et supporte leurs frais.</p>

    <h2>6. Livraison et partenaires</h2>
    <p>Lorsqu'il confie une livraison à une entreprise de livraison connectée, le Marchand contracte directement avec elle. L'Éditeur transmet les informations nécessaires à la course mais n'est pas partie à ce contrat et ne répond pas de son exécution.</p>

    <h2>7. Disponibilité et support</h2>
    <p>L'Éditeur s'efforce d'assurer un accès continu au Service et sauvegarde les données chaque jour, sans garantir l'absence d'interruption (maintenance, réseau, prestataires). Le support est joignable par WhatsApp depuis la gestion. Avec l'accord implicite du Marchand, l'équipe de support peut ouvrir une session d'assistance temporaire dans sa gestion : elle est limitée dans le temps, signalée au Marchand qui peut y mettre fin, et journalisée.</p>

    <h2>8. Données</h2>
    <p>Le Marchand est responsable du traitement des données de ses clients ; l'Éditeur les traite pour son compte, comme sous-traitant, dans le seul but de fournir le Service. Le traitement des données personnelles est décrit dans la <a href="/confidentialite" class="underline">politique de confidentialité</a>. Le Marchand peut exporter son catalogue et demander au support la fermeture de sa boutique et la remise de ses données ; elles sont alors supprimées dans un délai de 90 jours, sauf obligation légale de conservation.</p>

    <h2>9. Responsabilité</h2>
    <p>La responsabilité de l'Éditeur est limitée aux dommages directs et prouvés, et plafonnée au montant payé par le Marchand au cours des douze derniers mois. L'Éditeur ne répond pas des ventes perdues, ni des actes des clients, livreurs, entreprises de livraison et prestataires de paiement.</p>

    <h2>10. Durée, résiliation</h2>
    <p>Le contrat est conclu pour une durée indéterminée. Le Marchand peut demander la fermeture de sa boutique à tout moment. L'Éditeur peut résilier en cas de manquement grave, après mise en demeure restée sans effet pendant 15 jours.</p>

    <h2>11. Droit applicable</h2>
    <p>Les présentes conditions sont régies par le droit ivoirien. À défaut d'accord amiable, tout litige relève des tribunaux d'Abidjan.</p>
@endsection
