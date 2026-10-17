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
- **Erreur pendant la mise à jour** : le message est dans le journal de l'action ; le site sort du mode maintenance même
  en cas d'échec. Relancer à la main sur le serveur si besoin.
- **Changer les clés** : relancer `setup-auto-deploy.sh` (les anciennes cessent de fonctionner) et remplacer les secrets.
- **Couper l'automatisme** : supprimer le secret `DEPLOY_HOST`, ou `rm /etc/sudoers.d/jibiat-deploy` sur le serveur.
