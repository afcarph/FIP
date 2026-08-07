# Release Candidate 1

Scope: stability, data quality, observability and operational readiness. No new
end-user features.

## What changed

### Data quality

**`monitoring_date` is now read from every layout.** Every REGIONS 6-8 report
stored a null monitoring date. The line was in the document all along — *"Date
of Monitoring: August 04-10, 2026"*, on the last page — and the reader matched
it, then discarded it, because the code required a coverage start before
accepting it and that layout is precisely the one that states no coverage week.
The year now comes from the monitoring line itself, with the coverage year as a
fallback for layouts that omit it. A line with neither yields nothing rather
than a guess: a monitoring date in the wrong year fails validation against the
covered week and fails the whole report with it.

**`--replay` accepts a directory**, so an extractor fix can reach the reports it
would have got wrong. A reader fixed without that leaves the defect in the
database and the tests calling it gone.

### Observability

`doe_import_runs` gained per-phase durations — discovery, download, extraction,
validation, import, and a separately-measured total — plus reports
discovered/rejected, the extractor each report was read by, and how many pages
of the CMS library discovery walked.

One duration said a run took 62 seconds and nothing about which part did.
Discovery is a third party's API, extraction is CPU, import is our database;
they fail differently and are somebody else's problem in two cases out of three.

Every column is nullable. An absent measurement is not a measurement of zero,
and rows written by an older ingest stay valid.

### Operational readiness

- **`GET /api/v1/health`** — readiness: database (a real query, not a live
  connection), scheduler age, disk, PDF archive. 503 when a check is down.
- **`GET /api/health`** stays a shallow liveness probe. One that touches the
  database restarts healthy containers whenever the database hiccups.
- **`/admin/system`** — the operator dashboard, served from the same code as
  the health endpoint so page and probe cannot disagree.
- **`php artisan fip:check-config`** — refuses a configuration that is unsafe in
  production. Exit code 1 so a pipeline stops.

### Security

Staging moved to `https://fip.nelleeph.com` with a Let's Encrypt certificate,
TLS 1.3, HTTP→HTTPS redirect and HSTS (`max-age=31536000; includeSubDomains;
preload`), plus CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options` and
`Referrer-Policy`. Rate limiting is live at 60/min per IP for public endpoints.

Both mobile apps had their cleartext exceptions for the API host **removed**.
Only `localhost` and the Android emulator alias keep cleartext, for local
development.

### Tooling

**`layout_analysis.py`** describes a PDF page's geometry — JSON, CSV and an SVG
overlay of word boxes, row bands, candidate columns and reading order — and
asserts nothing about how the page should be read. Every extraction bug this
project has had was a plausible guess about layout; none were visible in the
extracted text and all were obvious in the coordinates.

**`scripts/probe_doe_library.py`** queries the DOE CMS directly, independent of
the ingest's own code. When discovery and reality disagree, a diagnostic that
shares the ingest's code can only tell you what the ingest already believes.

## Two investigations that closed

**The missing NCR report for 4–10 Aug 2026 is not a bug.** The DOE has not
published it. 1,500 of the newest library documents were scanned; zero name NCR
and that week. NCR publishes at a consistent five-day lag and Regions 6-8 at
three, so NCR legitimately trails by one publication for about two days each
week. Full evidence in
[investigations/ncr-2026-08-04.md](../investigations/ncr-2026-08-04.md).

**North Luzon is not a layout problem.** Every one of its 15 pages is a single
full-page image with zero vector objects, against 242 and 1,314 for the two
layouts that work — plus base-14 fonts, `(cid:9)` glyphs and tokens duplicated
four times inside half a point. These are scans with an OCR text layer. There
is no brand header because the recogniser never recovered one, and a parser
fitted to those coordinates would publish brand attribution nobody measured.
**Recommendation: keep rejecting them.** Evidence and overlays in
[investigations/north-luzon.md](../investigations/north-luzon.md).

## Schema

One additive migration,
`2026_08_08_000001_add_phase_timings_to_doe_import_runs`. Every column is
nullable or defaulted, so an older API runs against it unchanged and a rollback
of code needs no rollback of schema.

## Documentation

[architecture](../architecture.md) · [deployment](../deployment.md) ·
[api](../api.md) · [import runbook](../runbooks/import.md) ·
[recovery runbook](../runbooks/recovery.md)
