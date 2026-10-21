#!/usr/bin/env bash
#
# Installation de Jibiat Marchand sur un serveur Apache existant, par exemple le VPS LWS géré par
# ISPConfig qui héberge déjà Delivery App (Debian 13). À lancer en root :
#
#   DOMAIN=preprod.jibiat-marchand.com EMAIL=moi@exemple.ci CLOUDFLARE_API_TOKEN=… bash deploy/install-apache.sh
#
# Chaque boutique a son sous-domaine ({slug}.DOMAIN) : il faut un certificat « wildcard », que seule une
# validation DNS permet d'obtenir. ISPConfig ne sait pas le faire : l'application est donc installée À CÔTÉ
# d'ISPConfig, sans passer par le panneau (son propre hôte virtuel Apache, son PHP-FPM, son utilisateur
# système). Les sites d'ISPConfig (dont Delivery App) ne sont pas modifiés.
#
# Variables :
#   DOMAIN                (obligatoire) domaine de la plateforme, ex. preprod.jibiat-marchand.com
#   EMAIL                 (obligatoire) adresse de contact (certificat Let's Encrypt, expéditeur des emails)
#   CLOUDFLARE_API_TOKEN  jeton Cloudflare « Modifier la zone DNS » (obligatoire au premier lancement)
#   APP_ENV               staging (défaut : codes SMS affichés à l'écran) ou production
#   DEMO                  1 (défaut) = boutique de démonstration « demo », 0 = base vide
#   DELIVERY_APP_DOMAINS  domaine(s) des serveurs Delivery App joignables (ex. test.mondomaine.ci)
#   REPO, BRANCH          dépôt et branche (défaut : git@github.com:habibou84/ecommerce.git, main)
#   INSTANCE              nom de l'installation sur le serveur (défaut : jibiat-marchand, la préproduction) ;
#                         la production : INSTANCE=jibiat-production (voir docs/PRODUCTION-LWS.md)
#   APP_DIR               dossier de l'application (défaut : /var/www/INSTANCE)
#
# Relançable sans risque : base de données, .env et certificat sont conservés.

set -euo pipefail

DOMAIN="${DOMAIN:-}"
EMAIL="${EMAIL:-}"
CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN:-}"
APP_ENV="${APP_ENV:-staging}"
DEMO="${DEMO:-1}"
REPO="${REPO:-git@github.com:habibou84/ecommerce.git}"
BRANCH="${BRANCH:-main}"
CLOUDFLARE_INI=/etc/letsencrypt/cloudflare-jibiat.ini

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
. "$SCRIPT_DIR/lib.sh"
APP_DIR="${APP_DIR:-/var/www/$INSTANCE}"
APP_USER="$DEFAULT_USER"
APP_HOME="/var/lib/$APP_USER"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root (sudo -i)."
[[ -n "$DOMAIN" ]] || fail "indiquez le domaine : DOMAIN=preprod.jibiat-marchand.com EMAIL=… CLOUDFLARE_API_TOKEN=… bash $0"
[[ -n "$EMAIL" ]] || fail "indiquez une adresse e-mail : EMAIL=moi@exemple.ci"
[[ "$APP_ENV" == "staging" || "$APP_ENV" == "production" ]] || fail "APP_ENV : staging ou production."
[[ "$INSTANCE" =~ ^[a-z][a-z0-9-]{2,30}$ ]] || fail "INSTANCE : lettres minuscules, chiffres et tirets (ex. jibiat-production)."
# Une installation par domaine : refuse d'écraser celle d'un autre domaine
if [[ -f "$DEPLOY_CONF" ]] && ! grep -qx "DOMAIN=$DOMAIN" "$DEPLOY_CONF"; then
    fail "l'installation « $INSTANCE » sert déjà un autre domaine ($(grep '^DOMAIN=' "$DEPLOY_CONF" | cut -d= -f2)). Pour un second site, choisissez un autre INSTANCE."
fi

. /etc/os-release
[[ "$ID" == "debian" || "$ID" == "ubuntu" ]] || warn "Prévu pour Debian 13 ; système détecté : $PRETTY_NAME."
systemctl is-active --quiet apache2 || fail "Apache (apache2) n'est pas actif sur ce serveur : ce script est prévu pour un serveur Apache (ISPConfig)."
if [[ -d /usr/local/ispconfig ]]; then info "ISPConfig détecté : il n'est pas modifié."; fi

# ─────────────────────────── Paquets ───────────────────────────

export DEBIAN_FRONTEND=noninteractive
# Aucune question pendant l'installation des paquets (services à redémarrer, fichiers de configuration modifiés)
export NEEDRESTART_MODE=a NEEDRESTART_SUSPEND=1

step "Paquets (PostgreSQL, Supervisor, Node.js, Composer, Certbot, extensions PHP)"
apt-get update -q
apt-get install -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold git unzip openssl sudo curl postgresql supervisor nodejs npm composer \
    certbot python3-certbot-dns-cloudflare \
    php-fpm php-cli php-pgsql php-intl php-bcmath php-gd php-zip php-mbstring php-xml php-curl

PHP_BIN="$(command -v php)"
PHP_V="$($PHP_BIN -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
info "PHP $PHP_V ($PHP_BIN), Node.js $(node -v), Composer $(COMPOSER_ALLOW_SUPERUSER=1 composer --version --no-interaction 2>/dev/null </dev/null | awk '{print $3}')"
[[ "$(node -v | tr -d v | awk -F. '{print ($1*100)+$2}')" -ge 2019 ]] || fail "Node.js 20.19 ou plus requis (installé : $(node -v))."
$PHP_BIN -r 'exit(function_exists("imagettftext") && function_exists("imagecreatefromwebp") ? 0 : 1);' \
    || fail "l'extension GD de PHP doit gérer FreeType et WebP (images de partage, photos)."

# ─────────────────────────── Certificat wildcard (DNS Cloudflare) ───────────────────────────

step "Certificat HTTPS pour $DOMAIN et *.$DOMAIN"
if [[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
    info "Déjà obtenu (renouvelé automatiquement par certbot)."
else
    # Jeton déjà enregistré par une installation précédente (ex. la préproduction) : réutilisé
    if [[ -n "$CLOUDFLARE_API_TOKEN" ]]; then
        install -m 600 /dev/null "$CLOUDFLARE_INI"
        echo "dns_cloudflare_api_token = $CLOUDFLARE_API_TOKEN" > "$CLOUDFLARE_INI"
    fi
    [[ -s "$CLOUDFLARE_INI" ]] || fail "premier lancement : indiquez le jeton Cloudflare, CLOUDFLARE_API_TOKEN=… (voir docs/DEPLOIEMENT-PREPROD.md)."
    certbot certonly --non-interactive --agree-tos -m "$EMAIL" \
        --dns-cloudflare --dns-cloudflare-credentials "$CLOUDFLARE_INI" --dns-cloudflare-propagation-seconds 30 \
        --cert-name "$DOMAIN" -d "$DOMAIN" -d "*.$DOMAIN"
fi
# Apache relu après chaque renouvellement
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
cat > /etc/letsencrypt/renewal-hooks/deploy/jibiat-marchand-apache.sh <<'EOF'
#!/bin/sh
systemctl reload apache2
EOF
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/jibiat-marchand-apache.sh

# ─────────────────────────── Application ───────────────────────────

create_user
setup_postgres
fetch_code
write_env
configure_redis
configure_webpush
build_app
chown -R "$APP_USER:$APP_USER" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
chmod -R g+rX "$APP_DIR/public" "$APP_DIR/storage/app/public"

# ─────────────────────────── PHP-FPM et Apache ───────────────────────────

step "PHP-FPM (groupe dédié « $INSTANCE »)"
sed -e "s|__USER__|$APP_USER|g" -e "s|__PHP_V__|$PHP_V|g" -e "s|__INSTANCE__|$INSTANCE|g" "$APP_DIR/deploy/php-fpm.conf" > "/etc/php/$PHP_V/fpm/pool.d/$INSTANCE.conf"
systemctl enable "php$PHP_V-fpm" >/dev/null 2>&1 || true
systemctl restart "php$PHP_V-fpm"

step "Apache (hôte virtuel $DOMAIN et *.$DOMAIN)"
a2enmod -q ssl rewrite headers proxy proxy_fcgi setenvif >/dev/null
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__USER__|$APP_USER|g" -e "s|__PHP_V__|$PHP_V|g" \
    -e "s|__INSTANCE__|$INSTANCE|g" "$APP_DIR/deploy/apache.conf" > "/etc/apache2/sites-available/$INSTANCE.conf"
a2ensite -q "$INSTANCE" >/dev/null
apache2ctl configtest || fail "configuration Apache refusée (ci-dessus) : rien n'a été rechargé."
systemctl reload apache2

install_supervisor
install_cron
[[ "$APP_ENV" != "production" ]] || install_backup
save_deploy_conf

# ─────────────────────────── Vérification ───────────────────────────

step "Vérification"
sleep 2
hosts=("$DOMAIN" "admin.$DOMAIN")
[[ "$DEMO" != "1" ]] || hosts+=("demo.$DOMAIN")
for host in "${hosts[@]}"; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$host:443:127.0.0.1" "https://$host/up" || true)"
    info "https://$host/up → $code"
done
# Les autres installations du serveur (ex. la préproduction) répondent toujours
for conf in /etc/*-deploy.conf; do
    other="$(grep '^DOMAIN=' "$conf" | cut -d= -f2)"
    [[ -n "$other" && "$other" != "$DOMAIN" ]] || continue
    code="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$other:443:127.0.0.1" "https://$other/up" || true)"
    info "https://$other/up (autre installation) → $code"
done

step "Terminé"
cat <<EOF
    Plateforme        : https://$DOMAIN  (création de boutique)
    Console           : https://admin.$DOMAIN   (voir ci-dessous pour donner l'accès)
    Boutique démo     : https://demo.$DOMAIN    (gestion : /gestion, 07 00 00 00 01 / password)
    Dossier           : $APP_DIR (journaux : storage/logs)
    Mise à jour       : bash $APP_DIR/deploy/update.sh

    Accès à la console : créez d'abord votre compte (en ouvrant une boutique), puis
      cd $APP_DIR && sudo -u $APP_USER php artisan platform:admin 07XXXXXXXX
EOF
[[ "$DEMO" != "1" ]] || warn "Comptes de démonstration (mot de passe « password ») : à changer ou supprimer, le site est public."
