#!/usr/bin/env bash
#
# Zero-downtime-ish deployment for a single-host Docker installation.
#
# The ordering is deliberate: back up before touching anything, build the new
# images before stopping the old ones, put the app into maintenance mode only
# for the migration window, and verify health before declaring success.
#
#   ./infra/scripts/deploy.sh [git-ref]

set -Eeuo pipefail

REF="${1:-main}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE="docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml --env-file .env"

cd "$ROOT"

log()  { printf '\033[0;34m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
fail() { printf '\033[0;31m[%s] FAILED:\033[0m %s\n' "$(date +%H:%M:%S)" "$*" >&2; exit 1; }

trap 'fail "Deployment aborted on line $LINENO."' ERR

# --- preflight ---------------------------------------------------------------
[[ -f .env ]] || fail ".env is missing."
command -v docker >/dev/null || fail "Docker is not installed."

log "Deploying ref: $REF"

# --- 1. back up first --------------------------------------------------------
# Nothing else happens until there is a restore point.
log "Backing up the database…"
mkdir -p backups
BACKUP="backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"

$COMPOSE exec -T mysql sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" \
    --single-transaction --routines --triggers --quick fip' | gzip > "$BACKUP"

[[ -s "$BACKUP" ]] || fail "The backup is empty; refusing to continue."
log "Backup written to $BACKUP ($(du -h "$BACKUP" | cut -f1))"

# --- 2. fetch the new code ---------------------------------------------------
log "Fetching $REF…"
git fetch --all --prune
git checkout "$REF"
git pull --ff-only origin "$REF"

# --- 3. build before stopping anything ---------------------------------------
# A failed build must not leave the site down.
log "Building images…"
$COMPOSE build --pull

# --- 4. migrate under maintenance mode ---------------------------------------
log "Entering maintenance mode…"
$COMPOSE exec -T api php artisan down --render="errors::503" --retry=60 || true

log "Running migrations…"
$COMPOSE run --rm api php artisan migrate --force

# --- 5. roll the services ----------------------------------------------------
log "Restarting services…"
$COMPOSE up -d --remove-orphans

log "Warming caches…"
$COMPOSE exec -T api php artisan config:cache
$COMPOSE exec -T api php artisan route:cache
$COMPOSE exec -T api php artisan view:cache
$COMPOSE exec -T api php artisan queue:restart

$COMPOSE exec -T api php artisan up

# --- 6. verify ---------------------------------------------------------------
log "Waiting for health checks…"

for attempt in {1..30}; do
    if curl -fsS http://localhost/api/health >/dev/null 2>&1; then
        log "API is healthy."
        break
    fi

    [[ $attempt -eq 30 ]] && fail "The API did not become healthy in 60 seconds."
    sleep 2
done

curl -fsS http://localhost:8001/health >/dev/null 2>&1 \
    && log "AI service is healthy." \
    || log "WARNING: the AI service is not responding; forecasting will fall back."

log "Deployment complete: $(git rev-parse --short HEAD)"
