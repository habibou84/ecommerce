#!/usr/bin/env bash
#
# Mise à jour de Jibiat Marchand déjà installé par install-apache.sh, à lancer en root :
#
#   bash /var/www/jibiat-marchand/deploy/update.sh
#
# Récupère la dernière version de la branche, met à jour les dépendances, recompile l'interface,
# applique les migrations puis redémarre les processus.

set -euo pipefail

# shellcheck source=lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root."
[[ -f "$DEPLOY_CONF" ]] || fail "$DEPLOY_CONF absent : l'application n'a pas été installée avec install-apache.sh."
. "$DEPLOY_CONF"

BRANCH="$(as_app "git rev-parse --abbrev-ref HEAD")"

step "Mode maintenance"
as_app "php artisan down --retry=30 || true"
trap 'as_app "php artisan up"' EXIT

step "Code (branche $BRANCH)"
sync_code

step "Dépendances PHP"
as_app "composer install --no-dev --optimize-autoloader --no-interaction --no-progress"

step "Interface"
as_app "npm ci --no-audit --no-fund && npm run build"

step "Migrations et caches"
as_app "php artisan migrate --force"
# Rôles et offres : à jour avec le code (les prix des offres modifiés dans la console sont conservés)
as_app "php artisan db:seed --class=RolesAndPermissionsSeeder --force"
as_app "php artisan db:seed --class=PlanSeeder --force"
as_app "php artisan optimize"

step "Redémarrage des processus"
# PHP-FPM : vider le cache OPcache de l'ancien code
systemctl reload "php$PHP_V-fpm"
as_app "php artisan queue:restart"
supervisorctl restart jibiat-marchand-queue
supervisorctl status jibiat-marchand-queue

step "Terminé : $(as_app "git log -1 --format='%h %s'")"
