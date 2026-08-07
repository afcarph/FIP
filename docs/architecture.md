# Architecture

Four services over one MySQL database. Nothing here is novel; what matters is
which component owns which fact, because most of this project's bugs were two
components disagreeing about that.

```
   prod-cms.doe.gov.ph
   (Liferay GraphQL)
           │  documents(siteKey:"guest", flatten:true, sort:"dateModified:desc")
           ▼
   ┌───────────────────┐
   │  doe-pdf-ingest   │  Python. Discover → download → extract → validate → store.
   │  (cron, 06:00)    │  Keeps every original PDF so a fixed extractor can be re-run.
   └─────────┬─────────┘
             │ writes fuel_reports, fuel_prices, doe_import_runs
             ▼
   ┌───────────────────┐        ┌──────────────────┐
   │      MySQL        │◀───────│  backend (Laravel)│  /api/v1/**
   └───────────────────┘        └────────┬─────────┘
                                         │
                        ┌────────────────┼────────────────┐
                        ▼                ▼                ▼
                  frontend (Next)   mobile (Flutter)   ai-service (FastAPI)
```

## Who owns what

| Fact | Owner | Why not elsewhere |
|---|---|---|
| Published DOE figures | `fuel_reports` / `fuel_prices` | Area × product × brand ranges. Not per-station, because the DOE publishes no station-level data at all |
| Per-station pump prices | `gas_stations` / `station_prices` | The platform's own crowd and operator data, a different grain from the above |
| Weekly price *changes* | `price_advisories` | The DOE reports also publish *levels*; the two are not interchangeable |
| Ingest run history | `doe_import_runs` | Operational, written only by the ingest |

The ingest writes four tables and reads none of the platform's. The API reads
all of them and writes none of the ingest's. That boundary is why a broken
extractor cannot corrupt station data.

## Ingestion

`doe-pdf-ingest/` — a package of small modules, each replaceable:

| Module | Responsibility |
|---|---|
| `discovery.py` | `DiscoveryProvider` interface + `GraphQlDiscoveryProvider`. The pipeline depends on the interface only |
| `downloader.py` | Fetch, checksum, archive, prune |
| `extractor.py` | Coordinate-based table reading; region, coverage week and monitoring date from the document |
| `validator.py` | Rejects implausible rows and unusable reports; `accepted` is what gets stored |
| `storage.py` | Identity by checksum; a correction replaces a week rather than adding one |
| `pipeline.py` | Orchestration, phase timings, run status |
| `layout_analysis.py` | Diagnostic only. Describes a page's geometry; asserts nothing about how to read it |

### Three decisions worth knowing

**Discovery is GraphQL, not crawling.** `flatten: true` is mandatory — without
it the API returns only the root folder, 58 documents out of 14,898, and every
absence looks like proof of absence. No `search`, no `filter`: both silently
returned partial results.

**Extraction is coordinate-based.** `extract_tables` collapses product rows,
because the DOE's tables have no ruling lines. Plain text loses brand
attribution the moment a brand column is blank. Columns are anchored on the
header row's x-positions and cells assigned by nearest heading.

**Identity is the PDF checksum.** The DOE's filenames are not stable enough to
key on. A byte-identical document is already imported; a re-issued document for
a week already held is a *correction* and replaces it, rather than becoming a
second report for the same week.

## The API

Laravel 12, domain-organised under `app/Domain/{Doe,Pricing,Station}`.

Public, unauthenticated, rate-limited at 60/min per IP:

```
GET /api/v1/fuel/{latest,history,areas,brands,search,trends,reports,imports}
GET /api/v1/health
```

Authenticated:

```
GET /api/v1/admin/system      role: super_admin | system_admin | audit.view
```

Responses use one envelope — `{success, data, meta}` — with pagination nested
under `meta.pagination`. Reading `meta` as the page itself yields an undefined
total that falls back to the row count, which is a result count that always
equals the page size.

## Clients

**`frontend/`** — Next.js 15 App Router. The `(doe)` route group is public and
carries Dashboard, Search, History and API Status. `(app)` is authenticated and
carries the platform proper, including `/admin/system`.

**`mobile/`** — Flutter, Riverpod, go_router. `/doe/*` is a public shell with
Home, Search, History and Settings. Settings can repoint the app at another
host at run time; the compile-time `API_BASE_URL` remains the default.

Both clients compute data freshness with the same rule — age from the end of
the covered week, current if within seven days — and both have the same tests
for it, so the phone and the dashboard cannot start disagreeing about how old
the data is.

## What is deliberately absent

- **No station-level DOE data.** It does not exist in the source.
- **No parser for North Luzon.** Those documents are scans with an OCR text
  layer; see [investigations/north-luzon.md](investigations/north-luzon.md).
- **No second pricing model.** The ingest does not duplicate `station_prices`.
