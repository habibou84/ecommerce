# Mise à jour automatique des serveurs

Après chaque fusion, GitHub met lui-même à jour le serveur concerné :

- fusion dans `main` → préproduction (`bash /var/www/jibiat-marchand/deploy/update.sh`) ;
- fusion dans `production` → production (`bash /var/www/jibiat-production/deploy/update.sh`).

GitHub se connecte au serveur avec l'utilisateur `jmdeploy` et une clé qui ne peut lancer **que** cette commande :
pas de terminal, pas d'autre commande, pas de redirection. La clé est rangée dans les secrets du dépôt GitHub : personne
ne peut la relire. Le déroulement et le résultat sont dans GitHub › Actions › « Mise à jour des serveurs ».

Tant que les secrets ne sont pas enregistrés, l'automatisme ne fait rien ; la commande manuelle reste toujours possible.

## Mise en place (une seule fois)

1. **Préproduction à jour** (elle contient le script) : `bash /var/www/jibiat-marchand/deploy/update.sh`.
2. **Sur le serveur, en root** : `bash /var/www/jibiat-marchand/deploy/setup-auto-deploy.sh`.
   Le script crée l'utilisateur `jmdeploy`, l'autorise à lancer les deux mises à jour (rien d'autre), crée une clé pour
   la préproduction et une pour la production, puis affiche les valeurs à recopier.
   - S'il signale une ligne `AllowUsers`, ajouter `jmdeploy` à cette ligne de `/etc/ssh/sshd_config`, puis
     `systemctl reload ssh`.
3. **GitHub** › dépôt `ecommerce` › Settings › Secrets and variables › Actions › New repository secret, une fois par
   valeur :

   | Secret | Valeur |
   |---|---|
   | `DEPLOY_HOST` | adresse IP publique du serveur (espace client LWS) |
   | `DEPLOY_PORT` | port SSH affiché (souvent 22) |
   | `DEPLOY_USER` | `jmdeploy` |
   | `DEPLOY_HOST_KEY` | ligne `ssh-ed25519 …` affichée (empreinte du serveur : GitHub ne se connecte qu'à lui) |
   | `DEPLOY_KEY_PREPROD` | bloc de la clé de la préproduction, de `-----BEGIN` à `END … KEY-----` inclus |
   | `DEPLOY_KEY_PRODUCTION` | bloc de la clé de la production |

4. **Effacer les copies des clés** sur le serveur : `rm -r /root/.jibiat-deploy`.
5. **Essai** : GitHub › Actions › « Mise à jour des serveurs » › Run workflow (branche `main`). Le résultat se termine
   par la version installée et la vérification de `https://preprod.jibiat-marchand.com/up`.

Les clés ne s'envoient à personne : ni par message, ni dans une conversation.

## En cas de problème

- **« Permission denied (publickey) »** : secret `DEPLOY_KEY_…` incomplet (copier tout le bloc) ou `jmdeploy` absent
  de `AllowUsers`.
- **« Host key verification failed »** : `DEPLOY_HOST_KEY` ne correspond pas au serveur (relancer le script et recopier
  la ligne `ssh-ed25519 …`).
- **Erreur pendant la mise à jour** : le serveur revient de lui-même à la version précédente (voir ci-dessous) et
  l'action se termine en rouge (GitHub prévient par courriel) ; le message est dans son journal.
- **Changer les clés** : relancer `setup-auto-deploy.sh` (les anciennes cessent de fonctionner) et remplacer les secrets.
- **Couper l'automatisme** : supprimer le secret `DEPLOY_HOST`, ou `rm /etc/sudoers.d/jibiat-deploy` sur le serveur.

## Mise à jour sans coupure, et retour automatique à la version précédente

`deploy/update.sh` (automatique ou à la main) ne coupe le site que s'il le faut :

1. **le site reste en ligne** pendant la préparation : la nouvelle version est récupérée dans une copie de travail
   (`storage/app/deploy-build`) et son interface compilée à côté (`public/build-next`), avec les fichiers des versions
   de la dernière semaine (les pages déjà ouvertes continuent de fonctionner ; une page plus ancienne se recharge
   d'elle-même au premier clic). Une erreur ici ne change rien ;
2. **bascule** : code, dépendances PHP, migrations, nouvelle interface (remplacée d'un coup), caches, puis redémarrage
   en douceur des processus. Le **mode maintenance** n'est activé que s'il y a des migrations, le temps de les appliquer,
   et la base est alors copiée juste avant (`/var/backups/<installation>/avant-mise-a-jour.dump`). Pour forcer la
   maintenance pendant toute la bascule : `MAINTENANCE=1 bash deploy/update.sh` ;
3. **vérification du site** : `/up` (application et base) et la page d'accueil, sur le serveur lui-même.

Si une étape de la bascule échoue ou si la vérification ne passe pas, le serveur revient à la version précédente : code,
dépendances, interface, et base remise exactement comme avant les migrations (en une seule transaction ; rien n'est
perdu : pendant la maintenance, ni commande, ni file d'attente, ni tâche planifiée n'écrit dans la base). Le journal se
termine par « Revenu à la version précédente » et l'action GitHub est en rouge.

La version en échec reste sur GitHub : la fusion suivante (avec la correction) relance la mise à jour normalement.
Si le retour lui-même échoue (« intervention nécessaire »), la copie de la base reste disponible pour une restauration
à la main.

Des fichiers modifiés à la main sur le serveur bloquent la mise à jour avant tout changement : rien n'est annulé.
