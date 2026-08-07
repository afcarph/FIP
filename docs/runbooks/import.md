# Runbook — the daily import

The ingest runs at **06:00 Asia/Manila**. It walks the DOE's document library
newest-first, downloads anything new, extracts, validates and stores it.

## Read the run before doing anything

```bash
curl -s https://fip.nelleeph.com/api/v1/fuel/imports | jq '.data.last_run'
```

or `/admin/system` in the web client.

## A run that imports nothing is usually correct

This is the single most important thing in this document.

The DOE publishes **weekly**. On six days out of seven the run finds documents
it already holds and imports nothing. That is `no_changes`, and it is healthy.
Treating it as failure is what previously would have paged someone every
morning forever.

| `status` | Meaning | Do |
|---|---|---|
| `success` | New reports imported | nothing |
| `no_changes` | Everything found was already held | nothing |
| `partial` | Some imported, some rejected | read the messages; usually nothing |
| `failed` | Nothing imported, nothing recognised, something broke | investigate below |

## Rejections are not failures

`last_run.errors` lists **per-document** rejections. These recur every run and
are expected:

```
North-Luzon-…-21-27-July-2026.pdf: No region could be read from the document.
Price-Monitoring-of-11KG-Household-LPG-…: scored 0.00, below the 0.55 threshold
```

The DOE publishes LPG price sheets and the North Luzon scans, neither of which
this service reads. See
[investigations/north-luzon.md](../investigations/north-luzon.md). They cost a
line in the log and nothing else.

**Act on a rejection only when it names a document that used to import.** That
is a layout change.

## Symptoms

### "The dashboard shows last week"

First check whether the DOE has published this week's report at all.

```bash
python3 scripts/probe_doe_library.py --contains NCR --pages 15
```

Publication lags, measured over four weeks:

| Series | Lag after the week begins |
|---|---|
| NCR | 5 days |
| Regions 6-8 | 3 days |

So NCR trailing Regions 6-8 by one publication for a couple of days each week
is the system working. Investigate only if a region exceeds **nine days** since
its covered week began.

### `status: failed`

1. Is the CMS reachable? `python3 scripts/probe_doe_library.py --pages 1`
2. Did discovery find anything? `pdfs_discovered` of 0 with a reachable API
   means the query or the title matching has broken, not the network.
3. Did everything get rejected? Then a layout changed — go to the next section.

### A region stops importing

A layout change. Do **not** guess at the new layout.

```bash
cd doe-pdf-ingest
python layout_analysis.py storage/pdfs/<hash>/<file>.pdf --page 1 --out ./analysis
```

Read `analysis/<file>-p1.svg`. It draws word boxes, row bands and candidate
column boundaries over the page to scale. Two things to check first:

- **`SCANNED PAGE` in the output.** The document is an image with OCR text over
  it. Coordinates are a recogniser's estimates and no parser fitted to them is
  trustworthy. Stop.
- **Whether a header row exists.** The extractor anchors columns on it. If it
  is gone, the layout needs new anchoring, not a tweak.

### The scheduler has stopped

`/api/v1/health` returns 503 with `checks.scheduler.status: down` when no run
has started in 26 hours. Everything else stays green — the last run succeeded,
the data is valid, the API serves it. Only the age says anything is wrong.

```bash
systemctl status cron
tail -50 /srv/fip/doe-pdf-ingest/logs/cron.log
```

### Discovery walks more than one page

`/admin/system` warns on this. A daily run should stay on page one. More means
the lookback window no longer covers the gap between publications — raise
`LOOKBACK_DAYS`, or find out why the run has not fired for several days.

## Running it by hand

```bash
cd doe-pdf-ingest

python pipeline.py --dry-run            # extract and report, write nothing
python pipeline.py                      # a normal run
python pipeline.py --url <pdf-url>      # one document
python pipeline.py --backfill           # walk deeper into the archive
```

`--dry-run` first, always. It prints exactly what would be imported.

## Timings

`/admin/system` breaks each run into discovery, download, extraction,
validation and import. A run getting slower is only actionable once you know
which phase did:

| Phase slow | Usually |
|---|---|
| discovery | the DOE's API, or the lookback window widening |
| download | the DOE's file server, or a large backfill |
| extraction | CPU, or an unusually long report |
| import | the database |

The total is measured around the whole run, so it is larger than the sum of the
phases. That gap is real and shown deliberately.
