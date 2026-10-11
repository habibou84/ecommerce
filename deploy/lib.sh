# shellcheck shell=bash
# Fonctions communes à l'installation (install-apache.sh) et à la mise à jour (update.sh).
# Variables attendues : APP_DIR, APP_USER, APP_HOME (dossier personnel : clé Git, caches Composer et npm),
# PHP_BIN, et selon les fonctions : DOMAIN, EMAIL, REPO, BRANCH, APP_ENV, DEMO.

DB_NAME="${DB_NAME:-jibiat_marchand}"
DB_USER="${DB_USER:-jibiat}"
DEPLOY_CONF=/etc/jibiat-marchand-deploy.conf

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

# Base PostgreSQL et utilisateur dédiés ; DB_PASSWORD est vide si l'utilisateur existait déjà
setup_postgres() {
    step "PostgreSQL : base « $DB_NAME »"
    systemctl enable --now postgresql
    if sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DB_USER'" | grep -q 1; then
        info "Utilisateur $DB_USER déjà créé (mot de passe dans $APP_DIR/.env)."
        DB_PASSWORD=""
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
    env_set BILLING_MODE test
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
    as_app "php artisan optimize"
}

# File d'attente (messages WhatsApp, API Conversions, affectation automatique) sous Supervisor
install_supervisor() {
    step "Supervisor (file d'attente)"
    sed -e "s|__APP_DIR__|$APP_DIR|g" -e "s|__PHP__|$PHP_BIN|g" -e "s|__USER__|$APP_USER|g" -e "s|__HOME__|$APP_HOME|g" \
        "$APP_DIR/deploy/supervisor.conf" > /etc/supervisor/conf.d/jibiat-marchand.conf
    systemctl enable --now supervisor
    supervisorctl reread
    supervisorctl update
    supervisorctl restart jibiat-marchand-queue
}

# Planificateur Laravel (expéditions, abonnements, rapports, domaines, support) chaque minute
install_cron() {
    step "Tâches planifiées"
    echo "* * * * * $APP_USER cd $APP_DIR && $PHP_BIN artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/jibiat-marchand
    chmod 644 /etc/cron.d/jibiat-marchand
}

# Paramètres relus par update.sh
save_deploy_conf() {
    cat > "$DEPLOY_CONF" <<EOF
APP_DIR=$APP_DIR
APP_USER=$APP_USER
APP_HOME=$APP_HOME
PHP_BIN=$PHP_BIN
PHP_V=$PHP_V
DOMAIN=$DOMAIN
EOF
}
