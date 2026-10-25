# Planning de gestion et de maintenance · Jibiat Marchand

But : une plateforme sûre, rapide et disponible, sans surprise. Chaque tâche dit **qui** la fait :
**Moi** (le responsable de la plateforme, depuis la console ou son téléphone), **Claude** (dans une session de
développement), **Serveur** (automatique, déjà en place).

Rappel : jamais de mot de passe, clé ou fichier `.env` dans une conversation. Les secrets se saisissent dans la
console (champs masqués) ou directement sur le serveur.

## Tous les jours

| Quoi | Qui | Comment |
|---|---|---|
| Sauvegarde de la base | Serveur | Chaque nuit à 2 h 30 (`/var/backups/jibiat-production`, 7 copies tournantes) |
| Copie de la base avant chaque mise à jour | Serveur | `deploy/update.sh` ; retour automatique à la version précédente si la mise à jour échoue |
| Paiements Wave à valider | Moi | Alerte WhatsApp (puis e-mail) ; Console › Paiements |
| Erreurs du jour | Moi | E-mails de Sentry (si `SENTRY_LARAVEL_DSN` est réglé) |
| Purges (exports, reçus de webhooks, paniers) | Serveur | Tâches planifiées 4 h 10 et 4 h 20 |

## Chaque semaine

| Quoi | Qui | Comment |
|---|---|---|
| Lire le **Journal** de la console | Moi | Console › Journal : connexions inattendues, réglages changés |
| WhatsApp du support toujours relié | Moi | Console › WhatsApp (pastille verte) ; sinon le relier (QR code) |
| Alerte d'essai | Moi | Console › Paiements › « Envoyer une alerte d'essai » : WhatsApp ✓, e-mail ✓ |
| Espaces marchands en essai qui finit | Moi | Console › Tableau de bord |
| Mises à jour de sécurité des dépendances | Claude | `composer audit` et `npm audit --omit=dev` ; correctif, tests, PR |

## Chaque mois

| Quoi | Qui | Comment |
|---|---|---|
| **Test de restauration** d'une sauvegarde | Moi + Claude | Restaurer la dernière copie sur la préproduction et ouvrir un espace marchand |
| Copie des sauvegardes **hors du serveur** | Moi | Télécharger une copie (stockage externe chiffré) : un serveur perdu ne doit pas emporter les données |
| Espace disque, mémoire, durée des pages | Moi | Tableau de bord de l'hébergeur ; disque < 70 %, pages < 1 s |
| Mises à jour du système du serveur | Moi | `apt update && apt upgrade` (paquets de sécurité), redémarrage si demandé |
| Revue des membres d'équipe et des accès | Moi | Supprimer les comptes qui ne servent plus (console et boutiques de démonstration) |
| Coût de l'assistant IA | Moi | Console › Assistant IA |

## Chaque trimestre

| Quoi | Qui | Comment |
|---|---|---|
| Diagnostic de sécurité complet | Claude | Contrôle d'accès, webhooks, injections, dépendances (comme le diagnostic d'octobre 2026) |
| Changer le mot de passe de la console | Moi | Console › Mon compte (10 caractères au moins, jamais réutilisé ailleurs) |
| Renouveler les clés qui le permettent | Moi | Clé SMTP Brevo, jeton du service WhatsApp, clés des prestataires de paiement : nouvelle clé, puis suppression de l'ancienne |
| Performance | Claude | Requêtes lentes (journal PostgreSQL), index manquants, taille des images, poids des pages |
| Laravel, PHP, PostgreSQL, Node : versions encore suivies | Claude | Planifier la montée de version avant la fin du support |

## Chaque année

- Renouvellement des noms de domaine (jibiat-marchand.com) et vérification du renouvellement automatique des certificats.
- Mise à jour des CGU et de la politique de confidentialité ; déclaration ARTCI à jour.
- Exercice « serveur perdu » : réinstaller sur un serveur neuf à partir de la documentation (`docs/PRODUCTION-LWS.md`) et d'une sauvegarde.

## Bonnes pratiques retenues

**Sécurité**
- Console : mot de passe + code à usage unique (e-mail, sinon WhatsApp), session limitée à 12 h, e-mail d'alerte à
  chaque connexion, journal des actions. Ne jamais partager le compte : un compte par personne.
- Moindre privilège : chaque membre d'une boutique n'a que les droits de son rôle.
- Secrets chiffrés en base ou sur le serveur, jamais dans le code ni dans Git.
- Toute nouvelle fonctionnalité arrive avec ses tests ; la CI (tests PostgreSQL, style, build) doit être verte avant fusion.
- Préproduction d'abord, production ensuite ; une mise à jour qui échoue revient seule à la version précédente.

**Performance**
- Cache des réglages et des conseils (Redis), tâches longues en file d'attente, images en WebP redimensionnées.
- Surveiller les pages et requêtes lentes chaque trimestre ; ajouter un index plutôt qu'un serveur plus gros.

## En cas d'incident

1. **Site en panne** : vérifier l'hébergeur (serveur allumé, disque plein ?), puis la dernière mise à jour (le retour
   automatique a-t-il eu lieu ?). Ouvrir une session Claude avec le message d'erreur.
2. **Connexion suspecte** (e-mail d'alerte que vous n'attendiez pas) : changer tout de suite le mot de passe dans
   Mon compte (les autres sessions se ferment), lire le Journal, prévenir l'équipe.
3. **Fuite d'une clé** : la révoquer chez le fournisseur (Brevo, Wave, Meta…), en créer une nouvelle, la saisir dans la
   console ou sur le serveur.
4. **Perte de données** : restaurer la dernière sauvegarde sur la préproduction, vérifier, puis en production.
