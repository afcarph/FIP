# Runbook — the PDF archive

`/srv/doe-archive/pdfs` on the API host. **Deliberately outside `/opt/fip`**: a
`git clean`, a redeploy or a wiped checkout must not be able to touch it.

## Why this directory matters more than the database

It is the only copy of the source documents. The DOE does not keep superseded
weeks accessible, so for any week they have since replaced, these files exist
nowhere else — not on doe.gov.ph, not anywhere.

The database can be rebuilt from these PDFs. These PDFs cannot be rebuilt from
anything.

Everything in [recovery.md](recovery.md) — fixing the extractor and re-running
it, repairing a bad import, proving what a published figure actually was —
depends on them surviving.

## Layout

```
/srv/doe-archive/
├── pdfs/                       the archive, sharded by checksum prefix
├── manifest.sha256             SHA-256 of every file, relative to pdfs/
├── backups/
│   ├── 20260807-161701/        a snapshot: pdfs/ + its own manifest
│   ├── 20260807-161704/
│   └── latest -> …             symlink to the newest verified snapshot
└── bin/                        archive-backup.sh, archive-restore-test.sh
```

Snapshots are hardlinked against the previous one. The archive is append-only
in practice, so a daily snapshot costs the week's new documents rather than
another 111 MB. Two snapshots of the current archive occupy 112 MB, not 222.

## Schedule

```cron
30 2 * * * /srv/doe-archive/bin/archive-backup.sh        >> /var/log/fip-archive-backup.log 2>&1
0  4 * * 0 /srv/doe-archive/bin/archive-restore-test.sh  >> /var/log/fip-archive-restore-test.log 2>&1
```

Retention is 90 days, pruned only after a new snapshot has been written **and
verified**.

## Verifying

The live archive against its manifest:

```bash
cd /srv/doe-archive/pdfs && sha256sum -c ../manifest.sha256
```

That the backups actually restore — a real restore into a scratch directory,
verified against the snapshot's own manifest and then against the live archive:

```bash
/srv/doe-archive/bin/archive-restore-test.sh
```

Exit 0 means restorable. It has been checked against a deliberately corrupted
snapshot and exits 1 with the offending filename, so a pass is meaningful
rather than automatic.

## Restoring for real

Read the restore test's output first, then:

```bash
rsync -a /srv/doe-archive/backups/<stamp>/pdfs/ /srv/doe-archive/pdfs/
cd /srv/doe-archive/pdfs && sha256sum -c ../manifest.sha256
```

Then re-import, which rebuilds the database from the documents:

```bash
cd /opt/fip/doe-pdf-ingest && python pipeline.py --replay /srv/doe-archive/pdfs/
```

## Adding this week's documents

Until the ingest runs on the server, new PDFs arrive on whichever machine ran
the pipeline. Copy them in and re-manifest:

```bash
rsync -a doe-pdf-ingest/storage/pdfs/ staging:/srv/doe-archive/pdfs/
ssh staging 'cd /srv/doe-archive/pdfs && find . -name "*.pdf" -type f | sort \
  | xargs sha256sum | sed "s|\./||" > ../manifest.sha256'
```

Once the ingest is deployed, point `PDF_ARCHIVE_PATH` at `/srv/doe-archive/pdfs`
and this step disappears.

## What this does not protect against

**Losing the disk.** Snapshots are hardlinks on the same filesystem as the
archive. They survive a deletion, a bad replay and an accidental `rm` inside
`pdfs/`. They do not survive the volume going away.

The only part of this that leaves the host is the off-site sync, and it is
**not enabled**:

```bash
export ARCHIVE_S3_BUCKET=<bucket>      # then the nightly job syncs there
```

Until that is set, the backup script says so on every run:

```
WARNING: ARCHIVE_S3_BUCKET is not set. Snapshots are on the same disk as the
archive and will not survive losing it.
```

Treat that as the outstanding item it is.

## Current state

| | |
|---|---|
| Files | 76 (63 unique documents; the rest are the same PDFs under filenames from earlier discovery versions) |
| Size | 111 MB |
| Manifest | verifies clean |
| Snapshots | 2, 112 MB on disk |
| Restore test | passes; proven to fail on a corrupted snapshot |
| Off-site | **not configured** |
| Disk headroom | 72 GB free of 96 GB |
