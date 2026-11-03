#!/usr/bin/env bash
#
# Sauvegardes de Jibiat Marchand, installation du dossier où se trouve ce script (production ou préproduction), en root.
# Installé et planifié par update.sh (et les scripts d'installation) : rien à lancer à la main.
#
#   bash APP_DIR/deploy/backup.sh               sauvegarde de la nuit (base + fichiers), copie hors du serveur
#   bash APP_DIR/deploy/backup.sh --snapshot    copie de la base à la demande (avant-mise-a-jour.dump)
#   bash APP_DIR/deploy/backup.sh --verify      test de restauration dans une base à part (chaque dimanche)
#   bash APP_DIR/deploy/backup.sh --maintenance entretien de la nuit (PostgreSQL, fichiers temporaires)
#
# Dossier : /var/backups/<installation> (PLATFORM_BACKUP_PATH dans le .env)
#   quotidien/  base-AAAA-MM-JJ.dump (pg_dump -Fc) et fichiers-AAAA-MM-JJ.tar.gz (.env + storage/app)
#   (le dossier de l'installation porte son nom : jibiat-production, jibiat-marchand pour la préproduction)
#   hebdo/      copie du dimanche ; mensuel/ copie du 1er du mois
#   avant-mise-a-jour.dump, etat-*.json (lus par la console › Santé)
#
# Conservation (.env, facultatif) : BACKUP_KEEP_DAILY / _WEEKLY / _MONTHLY pour la base (14 / 8 / 12 en
# production, 3 / 0 / 0 ailleurs) et BACKUP_FILES_KEEP_DAILY / _WEEKLY / _MONTHLY pour les fichiers (7 / 4 / 3).
#
# Copie hors du serveur (S3 : Cloudflare R2, Backblaze B2…), active quand ces réglages sont dans le .env :
#   BACKUP_S3_ENDPOINT, BACKUP_S3_BUCKET, BACKUP_S3_KEY, BACKUP_S3_SECRET, BACKUP_ENCRYPTION_KEY
#   (facultatif : BACKUP_S3_PROVIDER, Cloudflare par défaut ; Other pour un autre fournisseur S3)
# Chaque fichier est chiffré (AES-256, BACKUP_ENCRYPTION_KEY) avant de partir. Voir docs/SAUVEGARDES.md.
# Même script que Jibiat (habibou84/deliverynew) : une correction se reporte dans les deux dépôts.

set -Eeuo pipefail

INSTANCE="$(basename "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)")"
# shellcheck source=lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $EUID -eq 0 ]] || fail "lancez ce script en root."
[[ -f "$DEPLOY_CONF" ]] || fail "$DEPLOY_CONF absent : l'application n'a pas été installée avec install-apache.sh."
. "$DEPLOY_CONF"
DB_NAME="$(env_get DB_DATABASE)"
DB_NAME="${DB_NAME:-$DEFAULT_DB}"

PRODUCTION=0
[[ "$(env_get APP_ENV)" == "production" ]] && PRODUCTION=1
DIR="$(backup_dir)"
TODAY="$(date +%F)"

setting() { local v; v="$(env_get "$1")"; echo "${v:-$2}"; }
if [[ "$PRODUCTION" == "1" ]]; then
    KEEP_DAILY="$(setting BACKUP_KEEP_DAILY 14)"; KEEP_WEEKLY="$(setting BACKUP_KEEP_WEEKLY 8)"; KEEP_MONTHLY="$(setting BACKUP_KEEP_MONTHLY 12)"
else
    KEEP_DAILY="$(setting BACKUP_KEEP_DAILY 3)"; KEEP_WEEKLY="$(setting BACKUP_KEEP_WEEKLY 0)"; KEEP_MONTHLY="$(setting BACKUP_KEEP_MONTHLY 0)"
fi
FILES_DAILY="$(setting BACKUP_FILES_KEEP_DAILY $((KEEP_DAILY < 7 ? KEEP_DAILY : 7)))"
FILES_WEEKLY="$(setting BACKUP_FILES_KEEP_WEEKLY $((KEEP_WEEKLY < 4 ? KEEP_WEEKLY : 4)))"
FILES_MONTHLY="$(setting BACKUP_FILES_KEEP_MONTHLY $((KEEP_MONTHLY < 3 ? KEEP_MONTHLY : 3)))"

pg() { sudo -u postgres "$@"; }
now_iso() { date -u +%Y-%m-%dT%H:%M:%SZ; }
size_of() { stat -c %s "$1" 2>/dev/null || echo 0; }
json_text() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | tr '\n' ' '; }

# État lu par la console (fichier lisible par l'application, sans donnée sensible)
write_state() {
    local name="$1" body="$2"
    printf '{"at":"%s",%s}\n' "$(now_iso)" "$body" > "$DIR/.etat-$name.json.tmp"
    chmod 644 "$DIR/.etat-$name.json.tmp"
    mv "$DIR/.etat-$name.json.tmp" "$DIR/etat-$name.json"
}

prepare_dir() { prepare_backup_dir; }

# Tables comptées au test de restauration (une absente des deux bases est ignorée)
VERIFY_TABLES=(users stores orders products shipments couriers)

# Supprime les fichiers « prefixe-* » de plus de N jours (0 : tous)
prune() {
    local dir="$1" prefix="$2" days="$3"
    if [[ "$days" -le 0 ]]; then
        find "$dir" -maxdepth 1 -type f -name "$prefix-*" -delete
    else
        find "$dir" -maxdepth 1 -type f -name "$prefix-*" -mtime +"$((days - 1))" -delete
    fi
}

# ---------------------------------------------------------------------------------------------------------
# Copie hors du serveur (rclone, configuré par variables d'environnement : aucun fichier de réglage à part)

offsite_ready() {
    [[ -n "$(env_get BACKUP_S3_ENDPOINT)" && -n "$(env_get BACKUP_S3_BUCKET)" && -n "$(env_get BACKUP_S3_KEY)" \
        && -n "$(env_get BACKUP_S3_SECRET)" ]]
}

rclone_env() {
    export RCLONE_CONFIG_DISTANT_TYPE=s3
    export RCLONE_CONFIG_DISTANT_PROVIDER="$(setting BACKUP_S3_PROVIDER Cloudflare)"
    export RCLONE_CONFIG_DISTANT_ENDPOINT="$(env_get BACKUP_S3_ENDPOINT)"
    export RCLONE_CONFIG_DISTANT_ACCESS_KEY_ID="$(env_get BACKUP_S3_KEY)"
    export RCLONE_CONFIG_DISTANT_SECRET_ACCESS_KEY="$(env_get BACKUP_S3_SECRET)"
    export RCLONE_CONFIG_DISTANT_REGION="$(setting BACKUP_S3_REGION auto)"
    # Clé limitée à un seul espace de stockage : ne pas chercher à le créer
    export RCLONE_CONFIG_DISTANT_NO_CHECK_BUCKET=true
    REMOTE="distant:$(env_get BACKUP_S3_BUCKET)/$INSTANCE"
}

# Envoie un fichier chiffré : distant:<espace>/<installation>/<dossier>/<nom>.enc
send() {
    local file="$1" folder="$2"
    BACKUP_PASS="$(env_get BACKUP_ENCRYPTION_KEY)" openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
        -pass env:BACKUP_PASS -in "$file" \
        | rclone rcat "$REMOTE/$folder/$(basename "$file").enc"
}

# Supprime les copies distantes plus anciennes que l'âge donné (dossier encore absent : rien à faire)
prune_remote() {
    rclone delete --min-age "$1" "$REMOTE/$2" --include "$3-*" 2>&1 || [[ $? -eq 3 ]]
}

offsite() {
    if ! offsite_ready; then
        rm -f "$DIR/etat-distant.json"
        info "Copie hors du serveur : non configurée (docs/SAUVEGARDES.md)."
        return 0
    fi
    local key
    key="$(env_get BACKUP_ENCRYPTION_KEY)"
    if [[ ${#key} -lt 24 ]]; then
        write_state distant '"ok":false,"message":"BACKUP_ENCRYPTION_KEY absente ou trop courte (24 caractères au moins) : rien n'\''est envoyé sans chiffrement."'
        warn "Copie hors du serveur : clé de chiffrement absente ou trop courte."
        return 0
    fi
    if ! command -v rclone >/dev/null; then
        info "Installation de rclone…"
        apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq rclone >/dev/null
    fi
    rclone_env

    local out sent=0 bytes=0 f
    # « || exit 1 » à chaque commande : set -e ne s'applique pas dans une condition « if »
    if ! out="$( {
        for f in "$@"; do
            send "$f" quotidien || exit 1
            if [[ "$(date +%u)" == "7" ]]; then send "$f" hebdo || exit 1; fi
            if [[ "$(date +%d)" == "01" ]]; then send "$f" mensuel || exit 1; fi
        done
        # Même conservation que sur le serveur
        prune_remote "${KEEP_DAILY}d" quotidien base || exit 1
        prune_remote "${FILES_DAILY}d" quotidien fichiers || exit 1
        prune_remote "$((KEEP_WEEKLY * 7 + 1))d" hebdo base || exit 1
        prune_remote "$((FILES_WEEKLY * 7 + 1))d" hebdo fichiers || exit 1
        prune_remote "$((KEEP_MONTHLY * 31 + 1))d" mensuel base || exit 1
        prune_remote "$((FILES_MONTHLY * 31 + 1))d" mensuel fichiers || exit 1
    } 2>&1 )"; then
        write_state distant "\"ok\":false,\"message\":\"Envoi impossible : $(json_text "$(echo "$out" | tail -n 2 | cut -c1-200)")\""
        warn "Copie hors du serveur en échec : $out"
        return 0
    fi
    for f in "$@"; do sent=$((sent + 1)); bytes=$((bytes + $(size_of "$f"))); done
    write_state distant "\"ok\":true,\"files\":$sent,\"bytes\":$bytes,\"provider\":\"$(json_text "$RCLONE_CONFIG_DISTANT_PROVIDER")\""
    info "Copie hors du serveur : $sent fichier(s) chiffré(s) envoyé(s)."
}

# ---------------------------------------------------------------------------------------------------------

nightly_failed() {
    trap - ERR
    rm -f "$DIR"/quotidien/*.tmp
    write_state sauvegarde "\"ok\":false,\"message\":\"La sauvegarde de la nuit a échoué (journal : /var/log/$INSTANCE-backup.log).\""
}

nightly() {
    prepare_dir
    local base="$DIR/quotidien/base-$TODAY.dump" files="$DIR/quotidien/fichiers-$TODAY.tar.gz"
    trap 'nightly_failed' ERR

    step "Base « $DB_NAME »"
    pg pg_dump -Fc "$DB_NAME" > "$base.tmp"
    # Vérifiée tout de suite : lisible par pg_restore et pas vide
    pg_restore --list "$base.tmp" > /dev/null
    [[ "$(size_of "$base.tmp")" -gt 1024 ]] || { echo "sauvegarde de la base vide"; false; }
    mv "$base.tmp" "$base"
    chmod 600 "$base"

    step "Fichiers (.env et storage/app : preuves, photos, logos, sessions WhatsApp)"
    tar -C "$APP_DIR" --exclude='storage/app/deploy-build' --exclude='storage/app/private/tmp' --warning=no-file-changed \
        -czf "$files.tmp" .env storage/app || [[ $? -eq 1 ]]
    tar -tzf "$files.tmp" > /dev/null
    mv "$files.tmp" "$files"
    chmod 600 "$files"

    # Copies du dimanche et du 1er du mois (liens : pas de place en plus)
    if [[ "$(date +%u)" == "7" ]]; then ln -f "$base" "$DIR/hebdo/"; ln -f "$files" "$DIR/hebdo/"; fi
    if [[ "$(date +%d)" == "01" ]]; then ln -f "$base" "$DIR/mensuel/"; ln -f "$files" "$DIR/mensuel/"; fi

    prune "$DIR/quotidien" base "$KEEP_DAILY"
    prune "$DIR/quotidien" fichiers "$FILES_DAILY"
    prune "$DIR/hebdo" base "$((KEEP_WEEKLY * 7))"
    prune "$DIR/hebdo" fichiers "$((FILES_WEEKLY * 7))"
    prune "$DIR/mensuel" base "$((KEEP_MONTHLY * 31))"
    prune "$DIR/mensuel" fichiers "$((FILES_MONTHLY * 31))"
    # Anciennes sauvegardes tournantes (base-1.dump à base-7.dump, avant ce script) : gardées 14 jours
    find "$DIR" -maxdepth 1 -type f -name 'base-[1-7].dump' -mtime +13 -delete

    local copies
    copies="$(find "$DIR/quotidien" "$DIR/hebdo" "$DIR/mensuel" -type f -name 'base-*' | wc -l)"
    write_state sauvegarde "\"ok\":true,\"base\":\"$(basename "$base")\",\"base_bytes\":$(size_of "$base"),\"files_bytes\":$(size_of "$files"),\"copies\":$copies,\"keep\":[$KEEP_DAILY,$KEEP_WEEKLY,$KEEP_MONTHLY]"
    trap - ERR
    info "Base : $(du -h "$base" | cut -f1), fichiers : $(du -h "$files" | cut -f1), $copies copie(s) de la base gardée(s)."

    step "Copie hors du serveur"
    offsite "$base" "$files"
}

# Copie de la base juste avant les migrations d'une mise à jour (lib.sh, aussi appelé par update.sh)
snapshot() { db_snapshot; }

# Test de restauration : la dernière sauvegarde est rechargée dans une base à part, comptée puis effacée
verify() {
    prepare_dir
    local latest test_db="${DB_NAME}_verif" counts="" table live restored problem=""
    latest="$(ls -1t "$DIR"/quotidien/base-*.dump 2>/dev/null | head -n1 || true)"
    if [[ -z "$latest" ]]; then
        write_state restauration '"ok":false,"message":"Aucune sauvegarde à restaurer."'
        fail "aucune sauvegarde à restaurer dans $DIR/quotidien."
    fi
    step "Restauration de $(basename "$latest") dans la base de test « $test_db »"
    pg dropdb --if-exists -q "$test_db" 2>/dev/null || true
    pg createdb "$test_db"
    trap 'pg dropdb --if-exists "'"$test_db"'" || true' EXIT
    if ! out="$(pg pg_restore --no-owner --no-privileges --exit-on-error -d "$test_db" < "$latest" 2>&1)"; then
        write_state restauration "\"ok\":false,\"backup\":\"$(basename "$latest")\",\"message\":\"Restauration impossible : $(json_text "$(echo "$out" | tail -n 2 | cut -c1-200)")\""
        fail "restauration impossible : $out"
    fi
    for table in "${VERIFY_TABLES[@]}"; do
        restored="$(pg psql -tAq -d "$test_db" -c "SELECT COUNT(*) FROM $table" 2>/dev/null || echo absent)"
        live="$(pg psql -tAq -d "$DB_NAME" -c "SELECT COUNT(*) FROM $table" 2>/dev/null || echo absent)"
        if [[ "$restored" == "absent" && "$live" != "absent" ]]; then
            problem="table $table absente de la sauvegarde"
        elif [[ "$restored" == "0" && "$live" =~ ^[0-9]+$ && "$live" -gt 0 ]]; then
            problem="table $table vide dans la sauvegarde ($live lignes dans la base)"
        fi
        [[ "$restored" =~ ^[0-9]+$ ]] && counts+="\"$table\":[$restored,${live//absent/null}],"
    done
    if [[ -n "$problem" ]]; then
        write_state restauration "\"ok\":false,\"backup\":\"$(basename "$latest")\",\"message\":\"$(json_text "$problem")\",\"counts\":{${counts%,}}"
        fail "$problem"
    fi
    write_state restauration "\"ok\":true,\"backup\":\"$(basename "$latest")\",\"counts\":{${counts%,}}"
    info "Restauration réussie : ${counts%,}"
}

# Entretien de la nuit : statistiques de PostgreSQL, fichiers temporaires
maintenance() {
    step "PostgreSQL : statistiques et nettoyage (VACUUM ANALYZE)"
    pg vacuumdb --analyze --quiet "$DB_NAME"
    step "Fichiers temporaires de plus de 7 jours"
    [[ ! -d "$APP_DIR/storage/app/private/tmp" ]] || find "$APP_DIR/storage/app/private/tmp" -type f -mtime +6 -delete
    info "Terminé."
}

case "${1:-}" in
    "") nightly ;;
    --snapshot) snapshot ;;
    --verify) verify ;;
    --maintenance) maintenance ;;
    *) fail "option inconnue : $1 (--snapshot, --verify ou --maintenance)" ;;
esac
