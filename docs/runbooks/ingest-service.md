# Runbook — the ingest service

Runs as a container on the API host, scheduled for **06:00 Asia/Manila**.

```
/srv/fip-ingest/
├── src/            the ingest source the image is built from
├── .env            database credentials and schedule (0600)
└── logs/           bind-mounted to /app/logs in the container
```

The archive is bind-mounted from `/srv/doe-archive/pdfs` — see
[archive.md](archive.md). Nothing the ingest writes lives inside a container.

## Everyday commands

```bash
cd /srv/fip-ingest/src
COMPOSE="sudo docker compose --env-file /srv/fip-ingest/.env"

$COMPOSE ps                                       # is it up
sudo docker logs --tail 50 fip-doe-ingest-ingest-1
$COMPOSE run --rm ingest python pipeline.py --dry-run   # extract, write nothing
$COMPOSE run --rm ingest python pipeline.py             # a run, now
$COMPOSE restart                                        # after a config change
```

The long-running container holds the schedule. `run --rm` starts a separate
one-off container and does not disturb it.

## Confirming the schedule

```bash
sudo docker logs fip-doe-ingest-ingest-1 | grep "next run"
# Scheduled 06:00 Asia/Manila — next run 2026-08-08T06:00:00+08:00
```

The container's clock is Asia/Manila, so `date` inside prints **PST** —
Philippine Standard Time, +08. Not Pacific.

## After changing the extractor

The container is built from `/srv/fip-ingest/src`, which is a copy. Update it
and rebuild:

```bash
rsync -a --delete --exclude .venv --exclude __pycache__ --exclude storage \
      --exclude logs --exclude .pytest_cache \
      doe-pdf-ingest/ staging:/srv/fip-ingest/src/
ssh staging 'cd /srv/fip-ingest/src && sudo docker compose --env-file /srv/fip-ingest/.env build && sudo docker compose --env-file /srv/fip-ingest/.env up -d'
```

Then apply the fix to data already stored — a fixed reader does not touch it
on its own:

```bash
$COMPOSE run --rm ingest python pipeline.py --replay /app/storage/pdfs/
```

A full replay of the archive takes roughly fifteen minutes; most of it is the
documents that cannot be read at all, where camelot and tabula both run before
the report is rejected.

## Deploy-order coupling

**Migrations first.** The ingest's model declares every column of
`doe_import_runs`, so a schema missing one fails the run's opening INSERT with
`Unknown column`. This is not graceful: the run dies before it does any work.

`finish_run` is the tolerant half — it drops counters whose column does not
exist — but `start_run` is a plain ORM insert and is not. Migrate, then deploy
the ingest.

## Known-good baseline

Established 8 August 2026, after the first containerised runs:

| | |
|---|---|
| Reports | 43 |
| Prices | 32,139 |
| Regions | NCR, REGIONS 6-8 |
| Coverage | 7 Oct 2025 → 4 Aug 2026 |
| Reports without a monitoring date | 0 |
| Reports whose coverage and monitoring years disagree | 0 |
| Rejected every run | 4 — two North Luzon scans, two Southern Luzon LPG sheets |

A run typically reports `discovery≈1.5s download≈11s extraction≈65s`. Almost
all the extraction time is the four documents that get rejected.

## Divergence to reconcile

`/srv/fip-ingest/src` is a copy of the repository's `doe-pdf-ingest/`, not a
checkout, because `/opt/fip` is behind and the branch has not been pushed.
Once it is, point the build at `/opt/fip/doe-pdf-ingest` and delete the copy —
two sources for one service is a bug waiting for the day they differ.
