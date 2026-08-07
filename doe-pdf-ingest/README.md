# DOE PDF ingest

Collects the Department of Energy's weekly fuel price monitoring publications
and loads them into the Fuel Intelligence Platform.

```
DOE portal → find new PDFs → download → store the original
           → extract tables → normalise → validate → MySQL → Laravel API
```

## Why this replaced the Looker scraper

The previous version drove the DOE's Looker Studio dashboard and read its JSON.
That dashboard is backed by a dataset last refreshed in **2021**, so everything
it served was five years stale. The department's own weekly PDFs are the live
source, and this service reads those instead. Playwright, the response
interceptor and the JSON parsing are gone.

## What the source actually contains

Worth knowing before reading the schema, because it is not what a price feed
usually looks like.

Each PDF covers one region for one week. Its table is:

```
AREA | PRODUCT | PETRON | SHELL | CALTEX | … | OVERALL RANGE | COMMON PRICE
```

Every brand cell holds a **min and a max** — that brand's price range in that
city. The last two columns are the area's overall range and the common price,
which the DOE states outright rather than deriving.

**There is no station-level data.** No station names, no addresses, no
coordinates. The published grain is *area by product by brand*. That is why
this service creates no `fuel_stations` table: it could only ever be empty. The
platform's own `gas_stations` remains the station directory, and
`/api/v1/stations` remains the way to browse it.

It also means nothing here duplicates `station_prices` or `fuel_price_history`,
which are per-station pump prices, or `price_advisories`, which are weekly
*changes* per region. Published price *levels* per city and brand are something
FIP had no table for.

## Discovery

**The listing crawler is removed.** Discovery queries Liferay's GraphQL API.

```
POST https://prod-cms.doe.gov.ph/o/graphql

documents(
  siteKey: "guest"
  flatten: true
  pageSize: 100
  page: N
  sort: "dateModified:desc"
) { totalCount items { id title contentUrl dateModified } }
```

Unauthenticated, introspection enabled. A daily run reads **one page in about
two seconds** and the newest reports are on it by construction.

### Why the crawler went

It read the portal's article listings, which worked for the field offices
publishing under "Price Monitoring" and never once reached NCR. Those reports
are public and served fine — the listings that link them use a different query
grammar, so NCR sat at **position 2,382 of 3,251** candidates. No page limit or
category balancing fixes an ordering that carries no meaning.

### Why `flatten: true` is mandatory

It defaults to false, which returns only the library's **root folder**: 58
documents, not one of them a price report. With it, the same site key returns
**14,898** and the current NCR report is on page one.

This one argument was the entire bug. `siteKey: "guest"` was correct
throughout — and note that the `/documents/d/guest/…` URL segment is a
friendly-URL namespace, not the site key. They coincide here; that is luck, not
a rule.

### Why `search` and `filter` are unused

Both look like they work and quietly lose reports.

| attempt | result |
| --- | --- |
| `search: "NCR Price Monitoring"` | 4,621 documents, not relevance-ranked, order unchanged |
| `search: "ncr-price-monitoring-07282026"` | the same 4,621 |
| `filter: "contains(title,'NCR')"` | 0 |

Titles are classified in-process instead, where the rules are visible and
tested. The CMS keeps titles clean — `NCR Price Monitoring 07282026.pdf` —
unlike the friendly-URL filenames, which run to four irreconcilable
conventions.

### Providers

The pipeline depends on `DiscoveryProvider`, never on GraphQL:

```python
class DiscoveryProvider(ABC):
    def discover(self, limit: int | None = None) -> list[DiscoveredPdf]: ...
```

`GraphQlDiscoveryProvider` is the default. `ManualSeedProvider` backs `--url`,
so a single document and a scheduled run share one code path. A sitemap or feed
provider would be another implementation and would change nothing downstream.

### Pagination

Three independent stops, because each fails somewhere alone:

- `LOOKBACK_DAYS` (14) — the library is newest-first, so a page ending older
  than the window means every later page is older still. This is what keeps a
  daily run to page one.
- `MAX_PAGES` (3) — a ceiling, so a misconfigured lookback cannot walk the
  archive. `--backfill` raises it to `BACKFILL_MAX_PAGES`.
- `max_candidates_per_run` (40) — stops early once enough is in hand.

Discovery does **not** deduplicate. A report is identified by the checksum of
its bytes, which is the importer's job — a provider guessing identity from a
URL would let a re-issued document through under a new name.

## Extraction

Three extractors are implemented and every one is *scored*; the best result
wins. The ordering is not the obvious one, and the reason is worth stating.

Camelot and tabula reconstruct a grid. These documents defeat that: the DOE
rules a box around each area block and draws **no line between the product
rows** inside it, so a grid reader merges RON 100 through KEROSENE into a
single row. Verified against the real NCR report, pdfplumber's own
`extract_tables` does exactly this.

Plain text extraction is worse. A blank brand column produces no text, so a
RON 95 line reads:

```
79.50 87.50 87.60 93.00 93.90 93.90 78.30 81.90 …
```

Nothing in that string says which brands those pairs belong to. Splitting on
whitespace assigns them left to right and silently gives Shell's price to
Caltex whenever a brand ahead of them is blank.

So the primary extractor is **coordinate-based**: every word is placed in a
column by its x position against boundaries taken from the header the document
prints. Blank columns stay blank because nothing lands in them. Camelot and
tabula stay in the chain and are scored, in case a future layout suits them.

Two bugs this module has had, both of which produced wrong data rather than an
error, and both now covered by tests:

- **A stale area label stealing the next area's table.** Page two of the real
  NCR report carries a leftover "Caloocan City" from page one sitting two
  points above the real "Muntinlupa City" — which is also why they interleave
  into `MCuanlotioncluapna C Citiyty` in the text layer. Each area publishes
  once, so the live label is the one not already used.
- **Rows bucketed by `int(top // 3)`.** That splits a row whenever its words
  straddle a multiple of three. Caloocan's RON 95 line sits at y=156.81 and
  lost every price it had. Rows are clustered on baseline gaps instead.

## Validation

Rejects what is wrong *about the document* — no region, a coverage week that
runs backwards or spans forty days, a table that mostly failed to parse. It
does not judge whether a price is reasonable in market terms; that belongs to
the platform.

A mostly-unusable table fails the whole report rather than importing the part
that parsed. A partial import is how a layout change becomes a week of quietly
missing areas.

## Idempotency

- A report is keyed on the **checksum of its PDF**, so the daily schedule is
  safe to run twice.
- A region publishes one report per week. A re-issued PDF with different bytes
  is a **correction**: it replaces that week's rows wholesale rather than
  merging, so figures the DOE has withdrawn do not survive.
- A run that finds nothing new is a success, recorded under its own
  `no_changes` status. Without that, a quiet week and a dead scheduler look
  identical.

## Quick start

```bash
cp .env.example .env      # point DOE_DB_* at the platform's database
docker compose up -d
```

Run once, immediately:

```bash
docker compose run --rm ingest python pipeline.py
```

Without Docker — note the system packages, which camelot and tabula need:

```bash
sudo apt-get install ghostscript poppler-utils default-jre-headless libgl1
python3.12 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python pipeline.py --dry-run
```

## Commands

```bash
python pipeline.py                    # a normal daily run
python pipeline.py --dry-run          # extract and report, write nothing
python pipeline.py --backfill         # walk the whole listing archive
python pipeline.py --url <pdf-url>    # one document
python pipeline.py --replay <path>    # re-extract a stored PDF

python scheduler.py                   # long-lived, fires at 06:00
python scheduler.py --run-now         # and once immediately
```

`--replay` is why the original PDFs are kept. Extraction is what will need
fixing, and a fix is only worth having if it can be re-run against the
documents it would have got wrong — by which time the DOE has published a
different week and keeps no accessible archive of the old one.

## Schema

Created by Laravel's migration; this service only writes rows.

**`fuel_reports`** — one published PDF. Unique on `checksum`, and on
`(region, coverage_start)`. Keeps `pdf_path` so extraction can be re-run, and
`extractor`/`quality` so a report imported at 0.60 can be looked at before one
imported at 0.98.

**`fuel_prices`** — one area × product × brand cell. `brand` is NULL on the
row carrying the area's overall range and common price. `fuel_code` is the
platform's `fuel_types.code`, resolved during extraction, so the data lands in
FIP's vocabulary. It is nullable because the DOE publishes RON 100 and the
platform has no fuel type for it — carried rather than filed under RON 97,
which would read as a plausible price for a different product.

**`doe_import_runs`** — one row per execution, including the ones that found
nothing.

## API

```
GET /api/v1/fuel/latest      most recent report per region, with provenance
GET /api/v1/fuel/history     the series, bounded by date
GET /api/v1/fuel/areas       cities and municipalities monitored
GET /api/v1/fuel/brands      brands monitored, with their coverage
GET /api/v1/fuel/search      area, brand, product, price range
GET /api/v1/fuel/trends      a weekly series for one grade
GET /api/v1/fuel/imports     ingestion health, for the admin dashboard
```

Ranges are never collapsed into a single price — that would invent a figure
nobody published. The one exception is `/trends`, where a line needs one value
per week; those points are midpoints, the minimum and maximum travel with them,
and the response says so.

`/imports` returns the last run, the last successful run, import duration,
record counts, failed runs, checksums and the latest publication date.

## Tests

```bash
pytest                     # 51 tests
ruff check . && ruff format --check .
```

They run against **the real published NCR report** for the week of 28 July
2026, kept in `tests/fixtures/`. Synthetic PDFs would not be worth much: every
bug this pipeline has had came from something the real document does and a
hand-built one would not — labels centred between rows, a stale label from the
previous page, baselines that straddle a bucket boundary.

The Docker job in CI proves camelot, pdfplumber and tabula all import in the
image and that the fixture extracts end to end, which the test job deliberately
does not cover.

## Operations

**Schedule.** 06:00 Asia/Manila, daily. The DOE publishes weekly; running daily
means a missed publication is picked up the next morning rather than the next
week.

**Retries.** Three attempts on discovery, 5s then 10s apart. The portal is not
highly available, and a transient 5xx would otherwise look like a week with
nothing published — a silent failure, since an empty run is legitimate.

**Health.** `doe_import_runs` is the record, surfaced at `/api/v1/fuel/imports`.

### When a report fails to import

1. Check `doe_import_runs.errors` for the run.
2. The PDF is in `storage/pdfs/`, sharded by checksum.
3. `python pipeline.py --replay storage/pdfs/xx/name-checksum.pdf`.
4. If extraction scored below the threshold, the layout has changed. The
   coordinate reader takes its columns from the header the document prints, so
   start by checking that the header parsed.

## Layout

| File | |
| --- | --- |
| `settings.py` | environment: credentials, hosts, thresholds |
| `discovery.py` | crawls the article listings for PDF links |
| `downloader.py` | fetches, checksums and stores the original |
| `extractor.py` | three extractors, scored; the table and the header |
| `validator.py` | document-level checks before anything is stored |
| `models.py` | SQLAlchemy description of the platform's tables |
| `storage.py` | engine, sessions, the write path, the run log |
| `pipeline.py` | orchestration, retries, CLI |
| `scheduler.py` | the 06:00 job |
| `logger.py` | logging and run correlation |
