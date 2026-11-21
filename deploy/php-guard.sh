#!/usr/bin/env bash
#
# Garde de PHP pour Jibiat Marchand, installé par le déploiement dans /usr/local/sbin/jibiat-php-guard (root).
#
# Pourquoi : une mise à jour du serveur peut faire passer les paquets PHP « génériques » (php-pgsql, php-cli…) à une
# nouvelle version de PHP. apt juge alors les extensions de la version utilisée par l'application (ex. php8.4-pgsql)
# inutiles et les retire, ou la commande « php » passe à la nouvelle version : l'application ne joint plus sa base de
# données (erreur 500 partout). C'est arrivé le 21/11/2026.
#
# Ce que fait la garde, pour chaque version de PHP listée dans /etc/jibiat-php-guard.conf (une ligne par
# installation : « 8.4 jibiat-production ») :
#   - réinstalle les paquets de cette version qui manquent (pgsql, redis, intl…) et les marque « installés à la main »
#     (apt ne les retire plus) ;
#   - réactive les extensions désactivées ;
#   - remet la commande « php » sur la version de la première ligne ;
#   - redémarre PHP-FPM de cette version si elle a dû réparer quelque chose.
# Journal : /var/log/jibiat-php-guard.log.
#
# Appels : après chaque installation de paquets (apt, avec --later : la réparation attend qu'apt ait fini), toutes les
# 10 minutes (cron), et à chaque déploiement.

set -uo pipefail

CONF="${JIBIAT_PHP_GUARD_CONF:-/etc/jibiat-php-guard.conf}"
LOG="${JIBIAT_PHP_GUARD_LOG:-/var/log/jibiat-php-guard.log}"
PACKAGES=(cli fpm common pgsql redis intl bcmath gd zip mbstring xml curl opcache readline)
# Extension → paquet qui la fournit (OPcache n'y est pas : PHP l'affiche sous un autre nom, « Zend OPcache »)
declare -A EXTENSIONS=([pdo_pgsql]=pgsql [pgsql]=pgsql [redis]=redis [intl]=intl [bcmath]=bcmath [gd]=gd [zip]=zip
    [mbstring]=mbstring [dom]=xml [curl]=curl)

if [[ "${1:-}" == "--later" ]]; then
    # Appelé par apt, qui tient encore son verrou : la réparation se fait 90 secondes après, en arrière-plan
    setsid bash -c "sleep 90; exec '$0'" >/dev/null 2>&1 </dev/null &
    exit 0
fi

[[ -f "$CONF" ]] || exit 0
# Une seule garde à la fois
exec 9>"/run/jibiat-php-guard.lock" 2>/dev/null || exec 9>"/tmp/jibiat-php-guard.lock"
flock -n 9 || exit 0

log() {
    echo "$(date '+%F %T') $*" >>"$LOG"
    logger -t jibiat-php-guard "$*" 2>/dev/null || true
}
installed() { dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'; }
has_extension() { "$1" -m 2>/dev/null | grep -qix "$2"; }
UPDATED=0
reinstall() {
    local cmd=(apt-get install -y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold "$1")
    DEBIAN_FRONTEND=noninteractive "${cmd[@]}" >>"$LOG" 2>&1 && return 0
    ((UPDATED)) && return 1
    UPDATED=1
    apt-get update -q >>"$LOG" 2>&1 || return 1
    DEBIAN_FRONTEND=noninteractive "${cmd[@]}" >>"$LOG" 2>&1
}

mapfile -t VERSIONS < <(grep -Eo '^[0-9]+\.[0-9]+' "$CONF" | awk '!seen[$0]++')
((${#VERSIONS[@]})) || exit 0

for v in "${VERSIONS[@]}"; do
    [[ -x "/usr/bin/php$v" ]] || { log "PHP $v absent du serveur : rien à garder."; continue; }
    repaired=0

    # Paquets de cette version : réinstallés s'ils ont été retirés, un par un (un paquet introuvable ne bloque pas
    # les autres) ; la liste des paquets est relue une fois si l'un d'eux reste introuvable
    missing=()
    for p in "${PACKAGES[@]}"; do
        installed "php$v-$p" || missing+=("php$v-$p")
    done
    if ((${#missing[@]})); then
        log "PHP $v : paquets absents, réinstallation : ${missing[*]}"
        for pkg in "${missing[@]}"; do
            if reinstall "$pkg"; then
                repaired=1
                log "PHP $v : $pkg réinstallé."
            else
                log "PHP $v : $pkg impossible à installer pour l'instant (apt occupé ou paquet introuvable), nouvel essai dans 10 minutes."
            fi
        done
    fi

    # Marqués « installés à la main » : apt ne les retire plus comme dépendances devenues inutiles
    keep=()
    for p in "${PACKAGES[@]}"; do
        installed "php$v-$p" && keep+=("php$v-$p")
    done
    ((${#keep[@]})) && apt-mark manual "${keep[@]}" >/dev/null 2>&1

    # Extensions désactivées : réactivées (ligne de commande et PHP-FPM)
    off=()
    for e in "${!EXTENSIONS[@]}"; do
        # Seulement si son paquet est là (sinon PHP signalerait une extension introuvable à chaque démarrage)
        installed "php$v-${EXTENSIONS[$e]}" || continue
        [[ -f "/etc/php/$v/mods-available/$e.ini" ]] || continue
        has_extension "/usr/bin/php$v" "$e" || off+=("$e")
        if [[ -x "/usr/sbin/php-fpm$v" ]] && ! has_extension "/usr/sbin/php-fpm$v" "$e"; then off+=("$e"); fi
    done
    if ((${#off[@]})); then
        mapfile -t off < <(printf '%s\n' "${off[@]}" | awk '!seen[$0]++')
        log "PHP $v : extensions réactivées : ${off[*]}"
        phpenmod -v "$v" "${off[@]}" >>"$LOG" 2>&1 && repaired=1
    fi

    if ((repaired)) && systemctl list-unit-files "php$v-fpm.service" >/dev/null 2>&1; then
        systemctl restart "php$v-fpm" && log "PHP $v : PHP-FPM redémarré."
    fi
done

# Commande « php » : toujours la version de l'application (première ligne)
main="/usr/bin/php${VERSIONS[0]}"
if [[ -x "$main" && "$(readlink -f /usr/bin/php 2>/dev/null)" != "$(readlink -f "$main")" ]]; then
    update-alternatives --set php "$main" >>"$LOG" 2>&1 && log "Commande php remise sur PHP ${VERSIONS[0]}."
fi
exit 0
