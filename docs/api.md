# API — DOE price monitoring

Base: `https://fip.nelleeph.com/api/v1`

Everything under `/fuel` is public and unauthenticated: these are the
Department of Energy's own published figures. Rate limited to **60 requests per
minute per IP**; the remaining budget is in `X-RateLimit-Remaining`.

## What the data is

The DOE publishes, per week and per region, a table of **area × product ×
brand** price *ranges*, plus an overall range and a common price it states
outright for each area.

It is **not** per-station data. There are no station names, addresses or
coordinates in the source, and none are invented here. Per-station pump prices
are a separate part of the platform (`/stations`, `/prices`).

A row with `brand` set is that brand's published range in that area. A row with
`brand: null` is the area's overall row, carrying `common_price`.

## Envelope

```json
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "request_id": "6d27acf5-…",
    "timestamp": "2026-08-07T19:31:58+08:00",
    "pagination": { "current_page": 1, "per_page": 50, "total": 3393, "last_page": 68, "from": 1, "to": 50 }
  }
}
```

**Pagination is nested under `meta.pagination`.** Reading `meta` as the page
itself yields an undefined total that quietly falls back to the row count — a
result count that always equals the page size.

Errors:

```json
{ "success": false, "error": { "code": "not_found", "message": "…" }, "meta": { … } }
```

## Endpoints

### `GET /fuel/latest`

Prices from the newest report held **per region**. Note that regions are not
always on the same week: NCR publishes at a five-day lag and Regions 6-8 at
three, so for about two days each week NCR is one publication behind. Use
`/fuel/imports` or `/health` to see the per-region coverage.

Filters: `region`, `area`, `province`, `brand`, `product`, `fuel_code`,
`min_price`, `max_price`, `branded_only`, `sort`, `per_page` (max 200).

### `GET /fuel/search`

Same shape and filters as `/fuel/latest`, across every week held rather than
the latest per region. Add `date_from` / `date_to` to bound the coverage week.

### `GET /fuel/history`

One area's published prices over time.

### `GET /fuel/trends`

A weekly series for one grade.

`?fuel_code=diesel&weeks=12` →

```json
[{ "coverage_start": "2026-08-04", "lowest": 82.95, "highest": 100.75, "midpoint": 90.83, "common": null }]
```

`midpoint` is the midpoint of the published range, not an average of
transactions. Charts should draw straight segments between weeks: the DOE
publishes one figure a week, and a curve renders prices for days nobody
measured.

### `GET /fuel/reports`

Every imported report, newest week first — coverage, region, area and row
counts, extractor, quality score, and `source_url` pointing at the original PDF.

### `GET /fuel/areas`, `GET /fuel/brands`

The distinct areas and brands present, for filter options.

### `GET /fuel/imports`

Ingest health and totals: `reports_total`, `prices_total`, `regions_total`,
`oldest_coverage_date`, `latest_reports` (one per region), `last_run`,
`failed_runs`, `recent_runs`.

`records_total` counts only the newest report per region. It is the right
number for "what is on screen now" and the wrong one for "how much have we
imported" — `prices_total` answers that.

`last_run.errors` carries **per-document rejections**, not run failures. The
DOE publishes LPG sheets and layouts this service does not handle; those are
rejected on every run by design and do not make a run unhealthy.

### `GET /health`

Readiness. `200` when ok or degraded, `503` when a check is down.

```json
{ "status": "ok",
  "checks": {
    "database":  { "status": "ok", "latency_ms": 3, "driver": "mysql", "reports": 6 },
    "scheduler": { "status": "ok", "last_run_at": "…", "hours_since_last_run": 2, "stale_after_hours": 26 },
    "disk":      { "status": "ok", "used_percent": 41.2, "free_bytes": 0, "total_bytes": 0 },
    "storage":   { "status": "ok", "path": "…", "files": 41, "bytes": 0 }
  } }
```

`GET /api/health` (outside `/v1`) is a **liveness** probe and deliberately does
not touch the database — one that did would restart healthy containers whenever
the database hiccupped.

### `GET /admin/system` — authenticated

Requires `super_admin`, `system_admin` or `audit.view`. Adds discovery state,
per-phase run durations, current coverage per region and parser versions. It
reports filesystem paths, disk capacity and the database driver, which is why
it is not public.

## Statuses

| `status` | Meaning | Action |
|---|---|---|
| `success` | Imported cleanly | none |
| `no_changes` | Ran; nothing new published | none — the normal outcome between weekly publications |
| `partial` | Imported some, rejected or errored on others | look, do not page |
| `failed` | Could not do its job | investigate |
| `running` | In flight | none |
