#!/usr/bin/env bash
#
# Nightly backup with retention and optional off-site upload.
#
# Run from cron:
#   0 2 * * * /opt/fip/infra/scripts/backup.sh >> /var/log/fip-backup.log 2>&1

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
S3_BUCKET="${BACKUP_S3_BUCKET:-}"

cd "$ROOT"
mkdir -p "$BACKUP_DIR"

STAMP="$(date +%Y%m%d-%H%M%S)"
FILE="$BACKUP_DIR/fip-$STAMP.sql.gz"

COMPOSE="docker compose -f infra/docker-compose.yml --env-file .env"

echo "[$(date -Is)] Starting backup"

# --single-transaction keeps InnoDB consistent without locking writers out.
$COMPOSE exec -T mysql sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" \
    --single-transaction --routines --triggers --events --quick \
    --default-character-set=utf8mb4 fip' | gzip -9 > "$FILE"

if [[ ! -s "$FILE" ]]; then
    echo "[$(date -Is)] ERROR: the dump is empty" >&2
    rm -f "$FILE"
    exit 1
fi

# Verify the archive before trusting it — a corrupt backup discovered during
# a restore is worse than no backup at all.
gzip -t "$FILE" || { echo "[$(date -Is)] ERROR: the archive is corrupt" >&2; exit 1; }

echo "[$(date -Is)] Wrote $FILE ($(du -h "$FILE" | cut -f1))"

if [[ -n "$S3_BUCKET" ]]; then
    echo "[$(date -Is)] Uploading off-site…"
    aws s3 cp "$FILE" "s3://$S3_BUCKET/mysql/" --storage-class STANDARD_IA
fi

# Prune only after a successful new backup.
DELETED=$(find "$BACKUP_DIR" -name 'fip-*.sql.gz' -type f -mtime "+$RETENTION_DAYS" -print -delete | wc -l)
echo "[$(date -Is)] Pruned $DELETED backup(s) older than $RETENTION_DAYS days"
echo "[$(date -Is)] Done"
