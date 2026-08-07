#!/usr/bin/env bash
#
# Back up the DOE PDF archive.
#
# The archive is the only copy of the source documents. The DOE does not keep
# superseded weeks accessible, so for any week they have since replaced, these
# files exist nowhere else on the internet. Every recovery procedure — fixing
# the extractor and re-running it, repairing a bad import, proving what a
# published figure actually was — depends on them surviving.
#
# Snapshots are hardlinked against the previous one. The archive is
# append-only in practice, so a daily snapshot costs only the week's new
# documents rather than another copy of the whole thing.
#
# Run from cron:
#   30 2 * * * /srv/doe-archive/bin/archive-backup.sh >> /var/log/fip-archive-backup.log 2>&1

set -Eeuo pipefail

ARCHIVE_ROOT="${ARCHIVE_ROOT:-/srv/doe-archive}"
SOURCE="$ARCHIVE_ROOT/pdfs"
BACKUP_DIR="$ARCHIVE_ROOT/backups"
RETENTION_DAYS="${RETENTION_DAYS:-90}"
S3_BUCKET="${ARCHIVE_S3_BUCKET:-}"

log() { echo "[$(date -Is)] $*"; }
fail() { echo "[$(date -Is)] ERROR: $*" >&2; exit 1; }

[[ -d "$SOURCE" ]] || fail "no archive at $SOURCE"

# An empty source with a successful exit would prune every good snapshot and
# leave nothing. Refuse instead.
FILE_COUNT="$(find "$SOURCE" -name '*.pdf' -type f | wc -l)"
[[ "$FILE_COUNT" -gt 0 ]] || fail "$SOURCE contains no PDFs; refusing to snapshot over good backups"

mkdir -p "$BACKUP_DIR"

STAMP="$(date +%Y%m%d-%H%M%S)"
TARGET="$BACKUP_DIR/$STAMP"
LATEST="$BACKUP_DIR/latest"

log "Snapshotting $FILE_COUNT file(s) from $SOURCE"

# rsync will not create nested parents. Made here rather than by rsync so a
# failure below leaves a directory this script owns and can remove.
mkdir -p "$TARGET/pdfs"

# A snapshot that failed part way must not be left where `latest` or a
# restore could later pick it up as complete.
cleanup_partial() {
    if [[ -d "$TARGET" && "$(readlink -f "$LATEST" 2>/dev/null)" != "$(readlink -f "$TARGET")" ]]; then
        log "Removing the incomplete snapshot at $TARGET"
        rm -rf "$TARGET"
    fi
}
trap cleanup_partial ERR

# --link-dest makes unchanged files hardlinks to the previous snapshot, so
# thirty daily snapshots of a 110 MB append-only archive cost ~110 MB plus the
# new documents, not 3.3 GB.
# Must point at the directory that corresponds to the *destination*, which is
# the snapshot's pdfs/ and not its root: rsync compares link-dest/<relpath>
# against dest/<relpath>, so a root here makes every lookup miss and every
# file gets copied in full while appearing to work.
LINK_ARG=()
if [[ -d "$LATEST/pdfs" ]]; then
    LINK_ARG=(--link-dest="$(readlink -f "$LATEST")/pdfs")
fi

rsync -a --delete "${LINK_ARG[@]}" "$SOURCE/" "$TARGET/pdfs/"

# The manifest travels with the snapshot. A backup you cannot verify is a
# backup you are guessing about.
(cd "$TARGET/pdfs" && find . -name '*.pdf' -type f | sort | xargs sha256sum | sed 's|\./||') \
    > "$TARGET/manifest.sha256"

MANIFEST_COUNT="$(wc -l < "$TARGET/manifest.sha256")"
[[ "$MANIFEST_COUNT" -eq "$FILE_COUNT" ]] \
    || fail "manifest has $MANIFEST_COUNT entries for $FILE_COUNT files"

# Verify what was just written, before anything is pruned on the strength of
# it. A corrupt backup found during a restore is worse than no backup.
log "Verifying the snapshot"
(cd "$TARGET/pdfs" && sha256sum -c --quiet ../manifest.sha256) \
    || fail "the snapshot does not match its own manifest"

ln -sfn "$TARGET" "$LATEST"

log "Wrote $TARGET ($(du -sh --apparent-size "$TARGET" | cut -f1) apparent, $(du -sh "$TARGET" | cut -f1) on disk)"

if [[ -n "$S3_BUCKET" ]]; then
    # Hardlinked snapshots sitting on the same disk as the archive protect
    # against deletion and not against losing the disk. Off-site is the only
    # part of this that survives the host.
    log "Syncing off-site to s3://$S3_BUCKET/doe-archive/"
    aws s3 sync "$SOURCE/" "s3://$S3_BUCKET/doe-archive/pdfs/" --storage-class STANDARD_IA --only-show-errors
    aws s3 cp "$TARGET/manifest.sha256" "s3://$S3_BUCKET/doe-archive/manifest-$STAMP.sha256" --only-show-errors
    log "Off-site sync complete"
else
    log "WARNING: ARCHIVE_S3_BUCKET is not set. Snapshots are on the same disk as the archive and will not survive losing it."
fi

# Prune only after a verified new snapshot exists.
DELETED=0
while IFS= read -r old; do
    [[ "$old" == "$TARGET" ]] && continue
    rm -rf "$old"
    DELETED=$((DELETED + 1))
done < <(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime "+$RETENTION_DAYS")

log "Pruned $DELETED snapshot(s) older than $RETENTION_DAYS days"
log "Done"
