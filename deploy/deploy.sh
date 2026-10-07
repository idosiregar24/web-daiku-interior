#!/usr/bin/env bash
#
# Daiku Interior — server-side deploy for staging (develop) and production
# (main), PRD §3.3 / §10.1. CI runs it over SSH after the test job passes
# (.github/workflows/ci.yml); it can also be run by hand on the server:
#
#   cd /srv/daiku-interior && bash deploy/deploy.sh main
#
# Steps: git pull (fast-forward only) → build images → maintenance mode on →
# DB backup → migrate → recreate containers → cache config/routes/views/
# events → restart Horizon → maintenance mode off.
#
# First-time server setup (clone, .env, TLS certificate, ProductionSeeder):
# README "HTTPS / Produksi".
#
# The whole script is one { … } block: bash parses it completely before
# running anything, so `git pull` rewriting this very file mid-run is safe.
# After pulling, the new version of the script is re-executed.
{
set -Eeuo pipefail

BRANCH="${1:-}"
MIN_COMPOSE_VERSION="2.24.4" # !override / !reset in docker-compose.prod.yml

log() { printf '\n\033[1;33m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;31m!!  %s\033[0m\n' "$*" >&2; }
die() { warn "$*"; exit 1; }

[[ -n "$BRANCH" ]] || die "Pemakaian: bash deploy/deploy.sh <branch>   (develop = staging, main = production)"

cd "$(dirname "${BASH_SOURCE[0]}")/.."

# --- 1. Pull, then continue with the freshly pulled script ---------------
if [[ "${DAIKU_DEPLOY_PULLED:-0}" != 1 ]]; then
    current="$(git rev-parse --abbrev-ref HEAD)"
    [[ "$current" == "$BRANCH" ]] || die "Checkout di server ada di branch '$current', bukan '$BRANCH'."

    log "git pull origin $BRANCH"
    git pull --ff-only origin "$BRANCH"

    DAIKU_DEPLOY_PULLED=1 exec bash deploy/deploy.sh "$BRANCH"
fi

# --- 2. Preflight ----------------------------------------------------------
[[ -f .env ]] || die ".env belum ada — salin dari .env.example dan isi nilai production (README \"HTTPS / Produksi\")."
grep -Eq '^APP_KEY=.+' .env || die "APP_KEY di .env masih kosong — isi dulu (php artisan key:generate --show)."
grep -Eq '^APP_DOMAIN=.+' .env || die "APP_DOMAIN di .env masih kosong."
grep -Eiq '^APP_DEBUG=(true|1)\s*$' .env && die "APP_DEBUG=true di .env — matikan di server (membocorkan stack trace & konfigurasi)."
grep -Eq '^BACKUP_ENCRYPTION_KEY=.+' .env || die "BACKUP_ENCRYPTION_KEY di .env masih kosong — PRD §9.5 mewajibkan backup terenkripsi (README \"Backup & Restore\")."
if grep -Eq '^REVERB_APP_(SECRET="?daiku_reverb_secret|KEY="?daiku_reverb_key)"?\s*$' .env || ! grep -Eq '^REVERB_APP_SECRET=.+' .env; then
    die "REVERB_APP_KEY/REVERB_APP_SECRET masih kosong atau nilai contoh — ganti dengan string acak (dipakai menandatangani private channel)."
fi
grep -Eq '^REVERB_ALLOWED_ORIGINS=.+' .env && ! grep -Eq '^REVERB_ALLOWED_ORIGINS="?\*"?\s*$' .env ||
    die "REVERB_ALLOWED_ORIGINS di .env harus berisi domain Daiku (APP_DOMAIN), bukan * ."

compose_version="$(docker compose version --short 2>/dev/null || true)"
compose_version="${compose_version#v}"
if [[ -z "$compose_version" ]] ||
    [[ "$(printf '%s\n%s\n' "$MIN_COMPOSE_VERSION" "$compose_version" | sort -V | head -n1)" != "$MIN_COMPOSE_VERSION" ]]; then
    die "Butuh Docker Compose v$MIN_COMPOSE_VERSION+ (terpasang: ${compose_version:-tidak ada})."
fi

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

# One-off container from the NEW image. `storage` is a shared volume, so
# e.g. `down` also puts the still-running old containers in maintenance mode.
artisan_run() { "${COMPOSE[@]}" run --rm --no-deps --user www-data app php artisan "$@"; }
artisan_exec() { "${COMPOSE[@]}" exec -T --user www-data "$1" php artisan "${@:2}"; }

# --- 3. Build while the old containers keep serving ------------------------
log "Build image ($(git rev-parse --short HEAD))"
"${COMPOSE[@]}" build --pull

log "Start MySQL & Redis"
"${COMPOSE[@]}" up -d --wait mysql redis

# --- 4. Backup + migrate behind maintenance mode ---------------------------
phase="pre-migrate"
on_error() {
    local status=$?
    if [[ "$phase" == "pre-migrate" ]]; then
        warn "Deploy gagal sebelum migrasi — skema belum berubah, container lama tetap jalan. Maintenance mode dimatikan."
        artisan_run up || true
    else
        warn "Deploy gagal saat/sesudah migrasi — aplikasi SENGAJA dibiarkan dalam maintenance mode."
        warn "Periksa log, perbaiki, lalu jalankan ulang deploy (atau: ${COMPOSE[*]} exec --user www-data app php artisan up)."
        warn "Backup tepat sebelum migrasi ada di disk backup (README \"Backup & Restore\")."
    fi
    exit "$status"
}
trap on_error ERR

log "Maintenance mode ON"
artisan_run down --retry=30

log "Backup database (sebelum migrasi)"
artisan_run db:backup

phase="migrate"
log "Migrate"
artisan_run migrate --force

# --- 5. Swap containers, warm caches ---------------------------------------
phase="post-migrate"
log "Recreate containers"
"${COMPOSE[@]}" up -d --remove-orphans
# Re-render the nginx template (production.conf is bind-mounted, so compose
# does not notice edits to it).
"${COMPOSE[@]}" restart nginx

log "Cache config/routes/views/events"
artisan_exec app optimize

log "Restart Horizon (container restarts it with the new code/config)"
artisan_exec worker horizon:terminate

log "Maintenance mode OFF"
artisan_exec app up

trap - ERR
docker image prune -f >/dev/null 2>&1 || true

log "Deploy $BRANCH selesai: $(git log -1 --format='%h %s')"
exit 0
}
