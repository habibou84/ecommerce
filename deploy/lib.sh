# shellcheck shell=bash
# Fonctions communes à l'installation (install-apache.sh) et à la mise à jour (update.sh).
# Variables attendues : INSTANCE, APP_DIR, APP_USER, APP_HOME (dossier personnel : clé Git, caches Composer et npm),
# PHP_BIN, et selon les fonctions : DOMAIN, EMAIL, REPO, BRANCH, APP_ENV, DEMO.
#
# INSTANCE nomme une installation sur le serveur (dossier, utilisateur, base, PHP-FPM, Apache, Supervisor, cron) :
# plusieurs installations cohabitent, par exemple la préproduction (jibiat-marchand) et la production
# (jibiat-production). Les noms de la préproduction, installée en premier, restent ceux d'origine.

INSTANCE="${INSTANCE:-jibiat-marchand}"
if [[ "$INSTANCE" == "jibiat-marchand" ]]; then
    DEFAULT_USER=jibiat
    DEFAULT_DB=jibiat_marchand
else
    DEFAULT_USER="${INSTANCE//-/_}"
    DEFAULT_DB="${INSTANCE//-/_}"
fi
DB_NAME="${DB_NAME:-$DEFAULT_DB}"
DB_USER="${DB_USER:-$DEFAULT_USER}"
DEPLOY_CONF="/etc/$INSTANCE-deploy.conf"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[1;33m    %s\033[0m\n' "$*"; }
fail() { printf '\n\033[1;31mErreur : %s\033[0m\n' "$*" >&2; exit 1; }
random() { openssl rand -hex "${1:-16}"; }

# Commande lancée en tant qu'utilisateur de l'application, avec ses outils (Git, Composer, npm)
as_user() {
    sudo -u "$APP_USER" -H env HOME="$APP_HOME" COMPOSER_HOME="$APP_HOME/.composer" \
        npm_config_cache="$APP_HOME/.npm" \
        GIT_SSH_COMMAND="ssh -i $APP_HOME/.ssh/id_ed25519 -o UserKnownHostsFile=$APP_HOME/.ssh/known_hosts -o StrictHostKeyChecking=accept-new" \
        bash -c "$*"
}

# Même chose, dans le dossier de l'application
as_app() { as_user "cd '$APP_DIR' && $*"; }

env_get() { grep -E "^$1=" "$APP_DIR/.env" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

env_set() {
    local key="$1" value="$2"
    if grep -qE "^$key=" "$APP_DIR/.env"; then
        sed -i "s|^$key=.*|$key=$value|" "$APP_DIR/.env"
    else
        echo "$key=$value" >> "$APP_DIR/.env"
    fi
}

# Utilisateur système dédié : l'application ne partage rien avec les sites d'ISPConfig
create_user() {
    step "Utilisateur système « $APP_USER »"
    if ! id "$APP_USER" >/dev/null 2>&1; then
        useradd --system --create-home --home-dir "$APP_HOME" --shell /usr/sbin/nologin "$APP_USER"
        info "Créé ($APP_HOME)."
    else
        info "Déjà présent."
    fi
    # Apache (www-data) lit les fichiers publics (images, interface compilée)
    usermod -a -G "$APP_USER" www-data
    mkdir -p "$APP_HOME/.ssh" "$APP_HOME/.composer" "$APP_HOME/.npm"
    chown -R "$APP_USER:$APP_USER" "$APP_HOME"
    chmod 700 "$APP_HOME/.ssh"
}

# Base PostgreSQL et utilisateur dédiés ; DB_PASSWORD est vide si l'utilisateur existe déjà et que le .env est en place
setup_postgres() {
    step "PostgreSQL : base « $DB_NAME »"
    systemctl enable --now postgresql
    if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
        if [[ -f "$APP_DIR/.env" ]]; then
            info "Utilisateur $DB_USER déjà créé (mot de passe dans $APP_DIR/.env)."
            DB_PASSWORD=""
        else
            # Lancement précédent interrompu avant l'écriture du .env : nouveau mot de passe
            info "Utilisateur $DB_USER déjà créé, .env absent : nouveau mot de passe."
            DB_PASSWORD="$(random 16)"
            sudo -u postgres psql -q -c "ALTER ROLE $DB_USER WITH LOGIN PASSWORD '$DB_PASSWORD'"
        fi
    else
        DB_PASSWORD="$(random 16)"
        sudo -u postgres psql -q -c "CREATE ROLE $DB_USER LOGIN PASSWORD '$DB_PASSWORD'"
    fi
    if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
        sudo -u postgres createdb -O "$DB_USER" "$DB_NAME"
    fi
}

# Clé de déploiement (lecture seule) puis clone ou mise à jour du dépôt
fetch_code() {
    step "Code source ($REPO, branche $BRANCH)"
    if [[ "$REPO" == git@* ]]; then
        if [[ ! -f "$APP_HOME/.ssh/id_ed25519" ]]; then
            as_user "ssh-keygen -q -t ed25519 -N '' -C 'deploy@$DOMAIN' -f '$APP_HOME/.ssh/id_ed25519'"
        fi
        if ! as_user "git ls-remote '$REPO'" >/dev/null 2>&1; then
            printf '\n\033[1;33mLe serveur n%s pas encore accès au dépôt.\033[0m\n' "'a"
            echo "Dans GitHub : dépôt ecommerce > Settings > Deploy keys > Add deploy key, collez cette clé (sans cocher « Allow write access ») :"
            echo
            cat "$APP_HOME/.ssh/id_ed25519.pub"
            echo
            echo "Puis relancez la même commande."
            exit 1
        fi
    fi

    if [[ ! -d "$APP_DIR/.git" ]]; then
        [[ ! -e "$APP_DIR" ]] || [[ -z "$(ls -A "$APP_DIR")" ]] || fail "$APP_DIR existe déjà et n'est pas un dépôt Git."
        mkdir -p "$APP_DIR"
        chown "$APP_USER:$APP_USER" "$APP_DIR"
        as_user "git clone --branch '$BRANCH' '$REPO' '$APP_DIR'"
    else
        info "Déjà cloné : mise à jour."
        sync_code
    fi
}

# Aligne le code du serveur sur la branche de GitHub. Les modifications locales de fichiers suivis bloquent la mise à jour.
sync_code() {
    as_app "git fetch origin '+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH'"
    if [[ -n "$(as_app "git status --porcelain --untracked-files=no")" ]]; then
        as_app "git status --short --untracked-files=no"
        fail "des fichiers du code ont été modifiés sur le serveur (ci-dessus) : annulez-les (cd $APP_DIR && git checkout -- .) puis relancez."
    fi
    as_app "git checkout -q '$BRANCH' && git reset -q --hard 'origin/$BRANCH'"
}

# .env (créé une seule fois, conservé ensuite)
write_env() {
    step "Configuration (.env)"
    if [[ -f "$APP_DIR/.env" ]]; then
        info ".env déjà présent : conservé."
        return
    fi
    [[ -n "$DB_PASSWORD" ]] || fail "le .env est absent mais l'utilisateur PostgreSQL « $DB_USER » existe déjà : supprimez-le (sudo -u postgres dropuser $DB_USER) ou fixez son mot de passe."

    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    # « staging » : les codes SMS s'affichent à l'écran tant qu'aucun fournisseur SMS n'est branché
    env_set APP_ENV "$APP_ENV"
    env_set APP_DEBUG false
    env_set APP_URL "https://$DOMAIN"
    env_set PLATFORM_DOMAIN "$DOMAIN"
    env_set LOG_STACK daily
    env_set LOG_LEVEL info
    env_set DB_DATABASE "$DB_NAME"
    env_set DB_USERNAME "$DB_USER"
    env_set DB_PASSWORD "$DB_PASSWORD"
    env_set SESSION_SECURE_COOKIE true
    env_set MAIL_FROM_ADDRESS "\"$EMAIL\""
    # Abonnements : paiements de test en préproduction ; en production, mode réel une fois les comptes configurés
    env_set BILLING_MODE "$([[ "$APP_ENV" == production ]] && echo live || echo test)"
    env_set CUSTOM_DOMAIN_CNAME "domaines.$DOMAIN"
    [[ -z "${DELIVERY_APP_DOMAINS:-}" ]] || env_set DELIVERY_APP_DOMAINS "$DELIVERY_APP_DOMAINS"
    chown "$APP_USER:$APP_USER" "$APP_DIR/.env"
    chmod 640 "$APP_DIR/.env"
}

# Dépendances, clé, interface, base de données, caches
build_app() {
    step "Dépendances PHP"
    as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

    [[ -n "$(env_get APP_KEY)" ]] || as_app "php artisan key:generate --force"

    step "Interface (compilation Vite)"
    as_app "npm ci --no-audit --no-fund && npm run build"

    step "Base de données (migrations, rôles, offres, lieux, thèmes)"
    as_app "php artisan migrate --force"
    as_app "php artisan db:seed --force"
    if [[ "$DEMO" == "1" ]] && [[ "$(as_app "php artisan tinker --execute='echo App\\Models\\Store::count();'" | tail -n1)" == "0" ]]; then
        info "Boutique de démonstration « demo »…"
        as_app "php artisan db:seed --class=DemoSeeder --force"
    fi
    as_app "php artisan storage:link --force >/dev/null"
    whatsapp_bridge_deps
    as_app "php artisan optimize"
}

# Notifications du navigateur (Web Push) : clés VAPID créées une seule fois, gardées ensuite (les changer obligerait
# chacun à réactiver les notifications)
configure_webpush() {
    step "Notifications du navigateur"
    if [[ -n "$(env_get VAPID_PUBLIC_KEY)" ]]; then
        info "Clés déjà présentes."
        return 0
    fi
    local vapid
    vapid="$(as_app "php artisan webpush:vapid")" || { warn "Clés impossibles à créer : notifications du navigateur désactivées."; return 0; }
    env_set VAPID_PUBLIC_KEY "$(echo "$vapid" | grep '^VAPID_PUBLIC_KEY=' | cut -d= -f2-)"
    env_set VAPID_PRIVATE_KEY "$(echo "$vapid" | grep '^VAPID_PRIVATE_KEY=' | cut -d= -f2-)"
    env_set VAPID_SUBJECT "mailto:$(env_get MAIL_FROM_ADDRESS | tr -d '"' | grep '@' || echo "contact@$(env_get PLATFORM_DOMAIN)")"
    info "Clés créées."
}

# Sessions et cache dans Redis (déjà présent sur le serveur et partagé avec d'autres logiciels, dont Delivery App sur
# ses bases 2 et 3) : bases 4 et 5, et un préfixe propre à l'installation (préproduction et production ne se mélangent
# pas). Sans Redis qui répond, ou sans l'extension PHP, rien ne change : sessions et cache restent dans la base.
# La file d'attente reste dans PostgreSQL (aucune tâche perdue si le serveur redémarre brutalement).
configure_redis() {
    step "Redis (sessions et cache)"
    if [[ "$(env_get SESSION_DRIVER)" == redis && "$(env_get CACHE_STORE)" == redis ]]; then
        info "Déjà sur Redis."
        return 0
    fi
    if ! command -v redis-cli >/dev/null 2>&1 || [[ "$(redis-cli ping 2>/dev/null)" != PONG ]]; then
        warn "Redis ne répond pas sur ce serveur : sessions et cache restent dans la base de données."
        return 0
    fi
    if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^redis$'; then
        info "Installation de l'extension PHP Redis (php$PHP_V-redis)"
        apt-get install -y -q "php$PHP_V-redis" >/dev/null 2>&1 || true
        if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^redis$'; then
            warn "Extension PHP Redis indisponible : sessions et cache restent dans la base de données."
            return 0
        fi
        systemctl reload "php$PHP_V-fpm" || true
    fi
    env_set REDIS_DB 4
    env_set REDIS_CACHE_DB 5
    env_set REDIS_PREFIX "$INSTANCE-"
    env_set SESSION_DRIVER redis
    env_set CACHE_STORE redis
    info "Sessions et cache sur Redis (bases 4 et 5, préfixe $INSTANCE-)."
    info "Les personnes connectées devront se reconnecter une fois."
}

# WhatsApp relié par QR code pour la boîte Messages (service whatsapp-bridge, Node) : jeton, port libre et adresse des
# événements propres à l'installation (créés une fois), puis dépendances npm (réinstallées seulement si elles ont
# changé). Avant « php artisan optimize » (réglages lus en cache).
whatsapp_bridge_deps() {
    step "WhatsApp relié par QR code (dépendances et réglages)"
    [[ -n "$(env_get WHATSAPP_BRIDGE_TOKEN)" ]] || env_set WHATSAPP_BRIDGE_TOKEN "$(random 32)"
    if [[ -z "$(env_get WHATSAPP_BRIDGE_PORT)" ]]; then
        # Préproduction et production sur le même serveur (avec le service de Jibiat) : chacune son port
        local port=3210
        while ss -ltn 2>/dev/null | grep -q ":$port " || grep -qs "^WHATSAPP_BRIDGE_PORT=$port$" /var/www/*/.env /home/*/*/.env; do
            port=$((port + 1))
        done
        env_set WHATSAPP_BRIDGE_PORT "$port"
    fi
    [[ -n "$(env_get WHATSAPP_BRIDGE_WEBHOOK)" ]] || env_set WHATSAPP_BRIDGE_WEBHOOK "https://api.$(env_get PLATFORM_DOMAIN)/webhooks/whatsapp-bridge"
    local lock_sum
    lock_sum="$(sha1sum "$APP_DIR/whatsapp-bridge/package-lock.json" | cut -d' ' -f1)"
    if [[ "$(cat "$APP_DIR/whatsapp-bridge/node_modules/.deploy-lock" 2>/dev/null || true)" != "$lock_sum" ]]; then
        as_app "cd whatsapp-bridge && npm ci --omit=dev --no-audit --no-fund && echo '$lock_sum' > node_modules/.deploy-lock" \
            || warn "dépendances du service WhatsApp non installées : la connexion par QR code reste indisponible."
    fi
}

# Processus du service WhatsApp sous Supervisor (fichier à part, relancé à chaque mise à jour)
install_whatsapp_bridge() {
    sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__NODE__|$(command -v node)|g" -e "s|__USER__|$APP_USER|g" \
        -e "s|__HOME__|$APP_HOME|g" -e "s|__INSTANCE__|$INSTANCE|g" \
        "$APP_DIR/deploy/supervisor-whatsapp.conf" > "/etc/supervisor/conf.d/$INSTANCE-whatsapp.conf"
    supervisorctl reread >/dev/null
    supervisorctl update >/dev/null
    supervisorctl restart "$INSTANCE-whatsapp" || warn "service WhatsApp non démarré : voir storage/logs/whatsapp-bridge.log"
}

# File d'attente (messages WhatsApp, API Conversions, affectation automatique) sous Supervisor
install_supervisor() {
    step "Supervisor (file d'attente)"
    sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP__|$PHP_BIN|g" -e "s|__USER__|$APP_USER|g" -e "s|__HOME__|$APP_HOME|g" \
        -e "s|__INSTANCE__|$INSTANCE|g" "$APP_DIR/deploy/supervisor.conf" > "/etc/supervisor/conf.d/$INSTANCE.conf"
    systemctl enable --now supervisor
    supervisorctl reread
    supervisorctl update
    supervisorctl restart "$INSTANCE-queue:*"
    install_whatsapp_bridge
}

# Planificateur Laravel (expéditions, abonnements, rapports, domaines, support) chaque minute
install_cron() {
    step "Tâches planifiées"
    echo "* * * * * $APP_USER cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1" > "/etc/cron.d/$INSTANCE"
    chmod 644 "/etc/cron.d/$INSTANCE"
}

# Sauvegarde de la base chaque nuit (production) : 7 copies tournantes, une par jour de la semaine
install_backup() {
    step "Sauvegarde nocturne de la base"
    local dir="/var/backups/$INSTANCE"
    install -d -m 700 -o postgres -g postgres "$dir"
    echo "30 2 * * * postgres pg_dump -Fc $DB_NAME > $dir/base-\$(date +\%u).dump" > "/etc/cron.d/$INSTANCE-backup"
    chmod 644 "/etc/cron.d/$INSTANCE-backup"
    info "Chaque nuit à 2 h 30 dans $dir (copiez-les aussi hors du serveur)."
}

# Paramètres relus par update.sh
save_deploy_conf() {
    cat > "$DEPLOY_CONF" <<EOF
INSTANCE=$INSTANCE
APP_DIR=$APP_DIR
APP_USER=$APP_USER
APP_HOME=$APP_HOME
PHP_BIN=$PHP_BIN
PHP_V=$PHP_V
DOMAIN=$DOMAIN
EOF
}
