#!/usr/bin/env bash
#
# Mise à jour de Jibiat Marchand déjà installé par install-apache.sh, à lancer en root :
#
#   bash /var/www/jibiat-marchand/deploy/update.sh      (préproduction)
#   bash /var/www/jibiat-production/deploy/update.sh    (production)
#
# L'installation mise à jour est celle du dossier où se trouve ce script.
# Récupère la dernière version de la branche, met à jour les dépendances, recompile l'interface,
# applique les migrations puis redémarre les processus.
#
# Tout se fait en mode maintenance. Si une étape échoue, ou si le site ne répond pas avant la sortie de maintenance,
# le serveur revient à la version précédente : code, et base de données telle qu'elle était juste avant les migrations
# (copie prise en maintenance : aucune commande n'est perdue). Le script se termine alors en erreur.

set -euo pipefail

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
# Accès au site pendant la maintenance, pour la vérification avant ouverture
SECRET="$(random 16)"

CODE_CHANGED=0
SNAPSHOT_TAKEN=0
FINISHED=0

dependencies() {
    as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"
    as_app "npm ci --no-audit --no-fund && npm run build"
}

restart_processes() {
    # PHP-FPM : vider le cache OPcache de l'ancien code
    systemctl reload "php$PHP_V-fpm"
    as_app "php artisan queue:restart"
    supervisorctl restart "$INSTANCE-queue"
}

# Le site répond-il ? (pendant la maintenance, grâce au cookie de passage)
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
        [[ "$code" == "200" && "$page" =~ ^[23] ]] && return 0
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
    as_app "git reset -q --hard '$PREVIOUS'" || { warn "Retour du code impossible."; failed=1; }
    if [[ $SNAPSHOT_TAKEN == 1 ]]; then
        if restore_database; then
            info "Base de données remise comme avant la mise à jour."
        else
            warn "Restauration de la base impossible (copie : $SNAPSHOT)."
            failed=1
        fi
    fi
    dependencies || { warn "Dépendances de la version précédente impossibles à installer."; failed=1; }
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
    as_app "php artisan up" || true
    [[ $FINISHED == 1 ]] || exit "$(( status == 0 ? 1 : status ))"
}

step "Mode maintenance"
as_app "php artisan down --retry=30 --secret='$SECRET' || true"
trap finish EXIT

step "Code ($INSTANCE, branche $BRANCH)"
sync_code
# Le code a changé : en cas d'échec, on revient à la version précédente
CODE_CHANGED=1

step "Dépendances PHP et interface"
dependencies

step "Copie de la base avant les migrations"
install -d -m 700 -o postgres -g postgres "$(dirname "$SNAPSHOT")"
sudo -u postgres pg_dump -Fc -f "$SNAPSHOT.tmp" "$DB_NAME"
mv "$SNAPSHOT.tmp" "$SNAPSHOT"
SNAPSHOT_TAKEN=1
info "$SNAPSHOT"

step "Migrations et caches"
as_app "php artisan migrate --force"
# Rôles et offres : à jour avec le code (les prix des offres modifiés dans la console sont conservés)
as_app "php artisan db:seed --class=RolesAndPermissionsSeeder --force"
as_app "php artisan db:seed --class=PlanSeeder --force"
as_app "php artisan optimize"

step "Redémarrage des processus"
restart_processes
supervisorctl status "$INSTANCE-queue"

step "Vérification avant ouverture"
check_site || fail "le site ne répond pas correctement avec la nouvelle version."

FINISHED=1
step "Terminé : $(as_app "git log -1 --format='%h %s'")"
