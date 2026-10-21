#!/usr/bin/env bash
#
# Mise à jour de Jibiat Marchand déjà installé par install-apache.sh, à lancer en root :
#
#   bash /var/www/jibiat-marchand/deploy/update.sh      (préproduction)
#   bash /var/www/jibiat-production/deploy/update.sh    (production)
#
# L'installation mise à jour est celle du dossier où se trouve ce script. Sans couper le site :
#   1. la nouvelle version est préparée à côté (copie de travail dans storage/app/deploy-build) et son interface
#      compilée pendant que l'ancienne reste en ligne ; en cas d'erreur, rien n'est changé ;
#   2. bascule : code, dépendances PHP, migrations, nouvelle interface (remplacée d'un coup), caches ;
#      le mode maintenance n'est activé que s'il y a des migrations, le temps de les appliquer (la base est alors
#      copiée juste avant) ;
#   3. redémarrage en douceur des processus, puis vérification que le site répond.
#
# Si une étape de la bascule échoue, ou si le site ne répond pas, le serveur revient à la version précédente : code,
# interface, et base de données telle qu'elle était juste avant les migrations. Le script se termine alors en erreur.
#
# Forcer le mode maintenance pendant toute la bascule (migration délicate) :
#   MAINTENANCE=1 bash /var/www/jibiat-marchand/deploy/update.sh

set -euo pipefail

# Tout le script est lu avant de commencer : la mise à jour remplace ce fichier en cours de route
main() {
    INSTANCE="$(basename "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)")"
    # shellcheck source=lib.sh
    . "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

    [[ $EUID -eq 0 ]] || fail "lancez ce script en root."
    [[ -f "$DEPLOY_CONF" ]] || fail "$DEPLOY_CONF absent : l'application n'a pas été installée avec install-apache.sh."
    . "$DEPLOY_CONF"

    BRANCH="$(as_app "git rev-parse --abbrev-ref HEAD")"
    PREVIOUS="$(as_app "git rev-parse HEAD")"
    DB_NAME="$(env_get DB_DATABASE)"
    DB_USER="$(env_get DB_USERNAME)"
    SNAPSHOT="/var/backups/$INSTANCE/avant-mise-a-jour.dump"
    # Accès au site pendant une maintenance, pour la vérification avant ouverture
    SECRET="$(random 16)"
    WORK="$APP_DIR/storage/app/deploy-build"
    NEXT="$APP_DIR/public/build-next"

    CODE_CHANGED=0
    SNAPSHOT_TAKEN=0
    MAINTENANCE_ON=0
    FINISHED=0

    step "Préparation de la nouvelle version (le site reste en ligne)"
    as_app "git fetch origin '+refs/heads/$BRANCH:refs/remotes/origin/$BRANCH'"
    if [[ -n "$(as_app "git status --porcelain --untracked-files=no")" ]]; then
        as_app "git status --short --untracked-files=no"
        fail "des fichiers du code ont été modifiés sur le serveur (ci-dessus) : annulez-les (cd $APP_DIR && git checkout -- .) puis relancez."
    fi
    local target
    target="$(as_app "git rev-parse 'origin/$BRANCH'")"
    as_app "git worktree prune"
    if [[ -d "$WORK/.git" || -f "$WORK/.git" ]]; then
        as_user "cd '$WORK' && git checkout -q --force --detach '$target' && git clean -qfd -e node_modules -e vendor"
    else
        as_app "git worktree add -f --detach '$WORK' '$target'"
    fi
    # Réglages (lus à la compilation) et paquets PHP de l'installation
    as_user "ln -sfn '$APP_DIR/.env' '$WORK/.env' && ln -sfn '$APP_DIR/vendor' '$WORK/vendor'"

    step "Interface (compilation à côté de la version en ligne)"
    # Dépendances npm réinstallées seulement si elles ont changé
    local lock_sum
    lock_sum="$(sha1sum "$WORK/package-lock.json" | cut -d' ' -f1)"
    if [[ "$(cat "$WORK/node_modules/.deploy-lock" 2>/dev/null || true)" != "$lock_sum" ]]; then
        as_user "cd '$WORK' && npm ci --no-audit --no-fund && echo '$lock_sum' > node_modules/.deploy-lock"
    fi
    as_app "rm -rf '$NEXT' && mkdir -p '$NEXT'"
    # Morceaux d'interface des versions récentes gardés : les pages déjà ouvertes continuent de fonctionner
    as_app "node '$WORK/deploy/carry-assets.mjs' public/build '$NEXT'"
    as_user "cd '$WORK' && npx vite build --outDir '$NEXT' --emptyOutDir false --logLevel warn"
    [[ -f "$NEXT/manifest.json" ]] || fail "la compilation de l'interface n'a pas produit de manifest.json : rien n'a été changé."

    # Maintenance seulement s'il y a des migrations (nouvelles, ou restées en attente)
    local maintenance="${MAINTENANCE:-0}"
    if [[ -n "$(as_app "git diff --name-only --diff-filter=A HEAD '$target' -- database/migrations")" ]] \
        || as_app "php artisan migrate:status --pending --no-ansi 2>/dev/null || true" | grep -q 'Pending'; then
        maintenance=1
    fi

    trap finish EXIT
    if [[ "$maintenance" == "1" ]]; then
        step "Bascule vers la nouvelle version (maintenance le temps des migrations)"
        as_app "php artisan down --retry=15 --secret='$SECRET' || true"
        MAINTENANCE_ON=1
        info "Copie de la base avant les migrations"
        install -d -m 700 -o postgres -g postgres "$(dirname "$SNAPSHOT")"
        sudo -u postgres pg_dump -Fc -f "$SNAPSHOT.tmp" "$DB_NAME"
        mv "$SNAPSHOT.tmp" "$SNAPSHOT"
        SNAPSHOT_TAKEN=1
        info "$SNAPSHOT"
    else
        step "Bascule vers la nouvelle version (sans maintenance : aucune migration)"
    fi

    # Le code change : en cas d'échec, on revient à la version précédente
    CODE_CHANGED=1
    as_app "git checkout -q '$BRANCH' && git reset -q --hard '$target'"
    as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"
    configure_redis
    # Toujours lancé : sans effet s'il n'y a rien à appliquer
    as_app "php artisan migrate --force"
    # Rôles et offres : à jour avec le code (les prix des offres modifiés dans la console sont conservés)
    as_app "php artisan db:seed --class=RolesAndPermissionsSeeder --force"
    as_app "php artisan db:seed --class=PlanSeeder --force"
    # Nouvelle interface d'un coup (deux renommages instantanés) ; l'ancienne est gardée pour un retour en arrière
    as_app "rm -rf public/build-prev && { [[ ! -d public/build ]] || mv public/build public/build-prev; } && mv '$NEXT' public/build"
    as_app "php artisan optimize"

    step "Redémarrage en douceur des processus"
    restart_processes
    install_supervisor
    supervisorctl status "$INSTANCE-queue:*" || true

    step "Vérification du site"
    check_site || fail "le site ne répond pas correctement avec la nouvelle version."

    if [[ $MAINTENANCE_ON == 1 ]]; then
        as_app "php artisan up"
        MAINTENANCE_ON=0
    fi
    as_app "rm -rf public/build-prev"
    FINISHED=1
    step "Terminé ($INSTANCE, branche $BRANCH) : $(as_app "git log -1 --format='%h %s'")"
}

restart_processes() {
    # PHP-FPM : rechargement sans couper les requêtes en cours (vide le cache OPcache de l'ancien code)
    systemctl reload "php$PHP_V-fpm"
    # Les processus de la file finissent leur tâche en cours puis repartent sur le nouveau code
    as_app "php artisan queue:restart"
}

# Le site répond-il ? (pendant une maintenance, grâce au cookie de passage)
site_answers() {
    local jar code page
    jar="$(mktemp)"
    for target in "--resolve $DOMAIN:443:127.0.0.1" ""; do
        # shellcheck disable=SC2086
        curl -s -o /dev/null -c "$jar" -b "$jar" $target "https://$DOMAIN/$SECRET" || true
        # shellcheck disable=SC2086
        code="$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" $target "https://$DOMAIN/up" || true)"
        # shellcheck disable=SC2086
        page="$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" $target "https://$DOMAIN/" || true)"
        [[ "$code" == "000" ]] && continue
        rm -f "$jar"
        info "https://$DOMAIN/up : $code, page d'accueil : $page"
        # « return » seul renverrait, depuis le nettoyage de sortie, le code d'erreur d'avant : codes explicites
        # Page d'accueil : seule une erreur du serveur (5xx) compte ; la préproduction répond 401 sans son code d'accès
        [[ "$code" == "200" && "$page" =~ ^[234] ]] && return 0
        return 1
    done
    rm -f "$jar"
    warn "https://$DOMAIN injoignable depuis le serveur."
    return 1
}

check_site() {
    for _ in 1 2 3 4 5 6; do
        site_answers && return 0
        sleep 5
    done
    return 1
}

# Base remise exactement comme avant les migrations (dans une seule transaction : tout ou rien)
restore_database() {
    { echo 'DROP SCHEMA public CASCADE;'
      echo "CREATE SCHEMA public AUTHORIZATION \"$DB_USER\";"
      sudo -u postgres pg_restore -f - "$SNAPSHOT"
    } | sudo -u postgres psql -q -1 -v ON_ERROR_STOP=1 -d "$DB_NAME" >/dev/null
}

rollback() {
    set +e
    local failed=0
    printf '\n\033[1;31m==> Échec de la mise à jour : retour à la version précédente (%s)\033[0m\n' "${PREVIOUS:0:7}"
    if [[ $MAINTENANCE_ON == 0 ]]; then
        as_app "php artisan down --retry=15 --secret='$SECRET' || true"
        MAINTENANCE_ON=1
    fi
    as_app "git reset -q --hard '$PREVIOUS'" || { warn "Retour du code impossible."; failed=1; }
    if [[ $SNAPSHOT_TAKEN == 1 ]]; then
        if restore_database; then
            info "Base de données remise comme avant la mise à jour."
        else
            warn "Restauration de la base impossible (copie : $SNAPSHOT)."
            failed=1
        fi
    fi
    as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress" || { warn "Dépendances PHP de la version précédente impossibles à installer."; failed=1; }
    # Interface précédente : celle gardée de côté, sinon recompilée
    if [[ -d "$APP_DIR/public/build-prev" ]]; then
        as_app "rm -rf public/build && mv public/build-prev public/build" || failed=1
    else
        as_app "npm ci --no-audit --no-fund && npm run build" || { warn "Interface de la version précédente impossible à compiler."; failed=1; }
    fi
    as_app "php artisan optimize:clear >/dev/null && php artisan optimize" || { warn "Caches impossibles à reconstruire."; failed=1; }
    restart_processes || { warn "Redémarrage des processus impossible."; failed=1; }
    if [[ $failed == 0 ]] && check_site; then
        step "Revenu à la version précédente : $(as_app "git log -1 --format='%h %s'")"
    else
        printf '\n\033[1;31m==> Le retour à la version précédente a aussi échoué : intervention nécessaire.\033[0m\n'
    fi
}

finish() {
    local status=$?
    trap - EXIT
    if [[ $FINISHED != 1 && $CODE_CHANGED == 1 ]]; then
        rollback
    fi
    [[ $MAINTENANCE_ON == 1 ]] && { as_app "php artisan up" || true; }
    as_app "rm -rf '$NEXT'" || true
    [[ $FINISHED == 1 ]] || exit "$(( status == 0 ? 1 : status ))"
}

main "$@"
exit
