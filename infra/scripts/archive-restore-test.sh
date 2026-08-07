#!/usr/bin/env bash
#
# Restore the archive from a backup and prove it came back intact.
#
# A backup nobody has restored is a hypothesis. This performs a real restore
# into a scratch directory, verifies every file against the snapshot's own
# manifest, and — when the live archive is present — checks the restored copy
# is byte-identical to it.
#
# It never writes to the live archive, so it is safe to run on a schedule:
#   0 4 * * 0 /srv/doe-archive/bin/archive-restore-test.sh >> /var/log/fip-archive-restore-test.log 2>&1
#
# To restore for real, having read the output:
#   rsync -a /srv/doe-archive/backups/<stamp>/pdfs/ /srv/doe-archive/pdfs/

set -Eeuo pipefail

ARCHIVE_ROOT="${ARCHIVE_ROOT:-/srv/doe-archive}"
BACKUP_DIR="$ARCHIVE_ROOT/backups"
SNAPSHOT="${1:-$BACKUP_DIR/latest}"

log() { echo "[$(date -Is)] $*"; }
fail() { echo "[$(date -Is)] ERROR: $*" >&2; exit 1; }

[[ -d "$SNAPSHOT" ]] || fail "no snapshot at $SNAPSHOT"
[[ -f "$SNAPSHOT/manifest.sha256" ]] || fail "$SNAPSHOT has no manifest"

WORK="$(mktemp -d -t fip-archive-restore-XXXXXX)"
trap 'rm -rf "$WORK"' EXIT

log "Restoring $(readlink -f "$SNAPSHOT") into $WORK"

# -L dereferences, so the restored copy is real files rather than hardlinks
# back into the snapshot. Verifying hardlinks to the thing you are testing
# proves nothing.
rsync -aL "$SNAPSHOT/pdfs/" "$WORK/pdfs/"

EXPECTED="$(wc -l < "$SNAPSHOT/manifest.sha256")"
RESTORED="$(find "$WORK/pdfs" -name '*.pdf' -type f | wc -l)"

[[ "$RESTORED" -eq "$EXPECTED" ]] || fail "restored $RESTORED file(s), manifest lists $EXPECTED"

log "Restored $RESTORED file(s); verifying against the snapshot manifest"

if ! (cd "$WORK/pdfs" && sha256sum -c --quiet "$SNAPSHOT/manifest.sha256"); then
    fail "the restored copy does not match the manifest"
fi

log "All $RESTORED file(s) match their recorded checksums"

# And against the live archive, when there is one. This catches the case the
# manifest cannot: a snapshot that is internally consistent but stale or
# incomplete relative to what is actually being served.
if [[ -d "$ARCHIVE_ROOT/pdfs" ]]; then
    LIVE="$(find "$ARCHIVE_ROOT/pdfs" -name '*.pdf' -type f | wc -l)"

    if DIFF="$(rsync -rcn --out-format='%n' "$ARCHIVE_ROOT/pdfs/" "$WORK/pdfs/")" && [[ -z "$DIFF" ]]; then
        log "Restored copy is byte-identical to the live archive ($LIVE files)"
    else
        # Not a failure: the archive grows between snapshots, and a file added
        # this morning is legitimately absent from last night's backup.
        log "NOTE: live archive has $LIVE file(s); differences against this snapshot:"
        echo "$DIFF" | sed 's/^/    /'
    fi
fi

log "Restore test passed"
