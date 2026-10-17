#!/usr/bin/env bash
#
# Mise à jour automatique depuis GitHub (voir docs/MISE-A-JOUR-AUTOMATIQUE.md), à lancer une fois en root :
#
#   bash /var/www/jibiat-marchand/deploy/setup-auto-deploy.sh
#
# Crée l'utilisateur « jmdeploy », qui ne peut rien faire d'autre que lancer la mise à jour de la préproduction
# et de la production (deploy/update.sh), et une clé de connexion par installation, limitée à cette seule commande.
# Affiche ensuite les valeurs à enregistrer dans les secrets du dépôt GitHub.
#
# Relancer le script remplace les clés (les anciennes cessent de fonctionner).

set -euo pipefail

DEPLOY_USER=jmdeploy
KEYS_DIR=/root/.jibiat-deploy
INSTANCES=(jibiat-marchand jibiat-production)

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
fail() { printf '\n\033[1;31mErreur : %s\033[0m\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "lancez ce script en root."
BASH_BIN="$(command -v bash)"

found=()
for instance in "${INSTANCES[@]}"; do
    [[ -f "/var/www/$instance/deploy/update.sh" ]] && found+=("$instance")
done
[[ ${#found[@]} -gt 0 ]] || fail "aucune installation trouvée dans /var/www (jibiat-marchand, jibiat-production)."

step "Utilisateur $DEPLOY_USER"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$DEPLOY_USER"
    passwd -l "$DEPLOY_USER" >/dev/null
    info "créé (sans mot de passe : connexion par clé uniquement)"
else
    info "existe déjà"
fi

step "Droit de lancer la mise à jour (et rien d'autre)"
sudoers=/etc/sudoers.d/jibiat-deploy
{
    echo "# Mise à jour automatique depuis GitHub (deploy/setup-auto-deploy.sh)"
    for instance in "${found[@]}"; do
        echo "$DEPLOY_USER ALL=(root) NOPASSWD: $BASH_BIN /var/www/$instance/deploy/update.sh"
    done
} > "$sudoers.tmp"
visudo -cf "$sudoers.tmp" >/dev/null || { rm -f "$sudoers.tmp"; fail "règle sudo invalide."; }
install -m 0440 "$sudoers.tmp" "$sudoers"
rm -f "$sudoers.tmp"
info "$sudoers"

step "Clés de connexion"
home="$(getent passwd "$DEPLOY_USER" | cut -d: -f6)"
install -d -m 0700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$home/.ssh"
install -d -m 0700 "$KEYS_DIR"
: > "$home/.ssh/authorized_keys.tmp"
for instance in "${found[@]}"; do
    rm -f "$KEYS_DIR/$instance" "$KEYS_DIR/$instance.pub"
    ssh-keygen -q -t ed25519 -N '' -C "github-$instance" -f "$KEYS_DIR/$instance"
    # La clé ne lance que la mise à jour de cette installation : ni terminal, ni redirection, ni autre commande
    echo "command=\"sudo -n $BASH_BIN /var/www/$instance/deploy/update.sh\",restrict $(cat "$KEYS_DIR/$instance.pub")" >> "$home/.ssh/authorized_keys.tmp"
    info "$instance"
done
install -m 0600 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$home/.ssh/authorized_keys.tmp" "$home/.ssh/authorized_keys"
rm -f "$home/.ssh/authorized_keys.tmp"

# Serveur SSH : port, et utilisateurs autorisés s'il y a une liste
port="$(sshd -T 2>/dev/null | awk '$1 == "port" { print $2; exit }')"
port="${port:-22}"
allow="$(sshd -T 2>/dev/null | awk '$1 == "allowusers" { $1 = ""; print }')"
if [[ -n "$allow" && " $allow " != *" $DEPLOY_USER "* ]]; then
    printf '\n\033[1;33m    Le serveur SSH n%sautorise que : %s\n    Ajoutez %s à la ligne AllowUsers de /etc/ssh/sshd_config, puis : systemctl reload ssh\033[0m\n' "'" "$allow" "$DEPLOY_USER"
fi
host_key="$(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)"
ip="$(hostname -I 2>/dev/null | awk '{ print $1 }')"

step "À enregistrer dans GitHub › dépôt ecommerce › Settings › Secrets and variables › Actions › New repository secret"
cat <<EOF

    DEPLOY_HOST       adresse IP publique du serveur (celle de l'espace client LWS ; ici : ${ip:-inconnue})
    DEPLOY_PORT       $port
    DEPLOY_USER       $DEPLOY_USER
    DEPLOY_HOST_KEY   $host_key
EOF
for instance in "${found[@]}"; do
    secret=DEPLOY_KEY_PREPROD
    [[ "$instance" == jibiat-production ]] && secret=DEPLOY_KEY_PRODUCTION
    printf '\n    %s : copiez tout le bloc ci-dessous, de BEGIN à END inclus\n\n' "$secret"
    cat "$KEYS_DIR/$instance"
done

cat <<EOF

    Ces clés ne servent qu'à GitHub : ne les envoyez à personne (ni par message, ni dans une conversation).
    Une fois les secrets enregistrés, effacez les copies du serveur :

        rm -r $KEYS_DIR

EOF
