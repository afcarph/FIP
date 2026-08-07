# RC1 status

Assessed 7 August 2026 against staging (`https://fip.nelleeph.com`) and the
working tree.

**Production readiness: 7 / 10.** RC1 is deployed to staging and running.
`https://fip.nelleeph.com` serves the dashboard and `/api/v1/health` over TLS
1.3; the ingest runs as a scheduled container; the PDF archive is on the server
with verified backups and a restore test. What holds the score at 7 rather than
higher is stated under Remaining risks: no off-site copy, no alerting on a
region going quiet, and the branch is still not in the shared remote.

## Completed

| # | Item | State | Evidence |
|---|---|---|---|
| 1.1 | Missing NCR report investigated | **Done** | DOE has not published it; 1,500 documents scanned, zero matches. [Investigation](../investigations/ncr-2026-08-04.md) |
| 1.2 | `monitoring_date` for REGIONS 6-8 | **Done** | All four stored Visayas PDFs now yield a date; NCR unchanged. 10 regression tests |
| 1.3 | Layout analysis utility | **Done** | JSON + CSV + SVG overlay; North Luzon identified as scanned. 10 tests |
| 2 | Phase timings and counters | **Done, unexercised** | Migration, model, pipeline, API. 10 tests. *No run has recorded them yet* |
| 3 | Health dashboard `/admin/system` | **Done, unverified live** | Page builds; API tested. *Never rendered against a live authenticated session* |
| 4 | `/api/v1/health` | **Done, undeployed** | 6 tests including a 503 case. Staging returns 404 — not yet deployed |
| 5 | Documentation | **Done** | architecture, deployment, api, RC1, two runbooks |
| 6 | Security | **Mostly done** | TLS 1.3, HSTS preload, CSP, X-Frame-Options, 60/min rate limit — all verified live. `fip:check-config` added |
| 7 | Regression tests | **Done** | See coverage below |
| 8 | Release report | **This document** | |

## Test results

All suites green, run 7 Aug 2026.

| Suite | Tests | Result |
|---|---|---|
| `doe-pdf-ingest` (pytest) | 130 | pass |
| `backend` (PHPUnit) | 103 (282 assertions) | pass |
| `frontend` (vitest) | 26 | pass |
| `mobile` (flutter test) | 24 | pass |
| **Total** | **283** | **pass** |

Static analysis: `ruff` clean, `pint` clean, `phpstan` level 6 clean, `eslint`
clean, `tsc --noEmit` clean, `flutter analyze` clean.

Coverage added this cycle, against the Priority 7 list:

| Required | Where | Notes |
|---|---|---|
| GraphQL discovery | `test_discovery.py` (20) | Pre-existing; asserts `search`/`filter` are never sent |
| Duplicate detection | `test_storage.py` (25) | Pre-existing; includes correction-replaces-week |
| Status transitions | `test_run_status.py` (9) | Pre-existing |
| North Luzon fixture | `test_north_luzon.py` (6) | **New.** One page of the real document |
| Monitoring date | `test_monitoring_date.py` (10) | **New.** Both layouts |
| Freshness | `freshness.test.ts` (8) + `freshness_test.dart` (8) | **New.** Same rule, both clients |

## Performance

Measured on staging, 7 Aug 2026.

| | |
|---|---|
| Last ingest run | 61.7s — 9 documents discovered, 6 already held, 0 imported |
| `/api/v1/fuel/imports` | 150ms |
| `/api/v1/fuel/latest?per_page=50` | 150ms, 38.9 KB |
| TLS handshake | TLS 1.3, `TLS_AES_256_GCM_SHA384` |
| Data held | 6 reports, 3,393 prices, 2 regions, back to 14 Jul 2026 |

Per-phase breakdown is **not yet available** — the columns exist but no run has
populated them. The first scheduled run after deployment will.

## Deployed, 8 August

| Surface | State |
|---|---|
| `https://fip.nelleeph.com/doe` and the three pages under it | 200, live data |
| `/api/v1/health` | 200 `ok` — database, scheduler, disk, archive |
| `/api/health` | 200, shallow liveness |
| `/api/v1/admin/system` | 401 unauthenticated, as intended |
| TLS | 1.3, HSTS preload, CSP, X-Frame-Options DENY |
| Rate limiting | 60/min, decrementing |
| `fip:check-config` | all checks pass |
| Ingest | containerised, next run 06:00 Asia/Manila |
| Archive | `/srv/doe-archive`, 76 files, nightly snapshot, weekly restore test |

Data held: **43 reports, 32,100 prices**, 7 Oct 2025 → 4 Aug 2026, every report
carrying a monitoring date.

### Found by deploying, and fixed

Three defects that only appeared once the code was running on the host.

**Ingest timestamps were read eight hours out.** The ingest writes naive UTC —
MySQL DATETIME carries no zone — and Laravel cast the same digits in
Asia/Manila. A run 56 minutes old reported as 8 hours old, and the staleness
check that guards against a dead scheduler is built on exactly that number.

**The health endpoint reported an empty archive.** It inspected a path inside
the API container that nothing writes to, while the real archive sat on the
host with 76 files. The archive is now mounted read-only into the container.

**`fip:check-config` failed a correctly configured host**, because it tested
the archive for write access. The API only reports on the archive; the ingest
owns it and the read-only mount is deliberate.

## Known issues

| Issue | Severity | Position |
|---|---|---|
| **No off-site copy of the archive** | **High** | `ARCHIVE_S3_BUCKET` unset. Snapshots share a disk with the archive |
| **The branch is not in the shared remote** | **High** | Deployed by rsync. `/opt/fip` is ahead of origin, and `/srv/fip-ingest/src` is a copy rather than a checkout |
| **`/admin/system` never rendered against a live session** | Medium | Needs an admin account, which is yours to create. The endpoint is covered by tests and returns 401 correctly; the page is not |
| **No alerting when a region goes quiet** | Medium | The health check covers a dead scheduler, not a region that stops updating |
| North Luzon and Southern Luzon LPG documents rejected every run | Low | By design. Scans with an OCR text layer — see the investigation |
| No NCR report for 4–10 Aug | None | Not a fault. Due ~9 Aug on the observed five-day lag |
| Phase timings unpopulated on existing rows | Low | Nullable by design; older runs measured nothing |
| `last_run.errors` carries per-document rejections | Low | Field name invites the wrong reading. Renaming is an API change, deferred |

## Remaining risks

**Nothing in RC1 has run in anger.** The phase timings, the health endpoint and
the config checker are all tested in isolation and none has been through a real
scheduled run against production data. This is the largest risk in the release
and the only one that a deployment plus one morning's run would clear.

**One data source, no contract.** The DOE can change a layout without notice,
and has. The mitigations are real — quality scoring, per-document rejections
that do not fail a run, kept originals, `--replay`, and now the layout tool —
but a layout change still means a region silently stops updating until someone
reads the run log. There is no alerting on that yet; the health endpoint covers
the scheduler dying, not a region going quiet.

**Two regions, not seventeen.** NCR and Regions 6-8 import. Everything else the
DOE publishes is either a layout not yet supported or a scan. Coverage is a
product question, but anyone reading "Fuel Intelligence Platform" will expect
more than two regions.

**The archive is the only copy.** The DOE does not keep superseded weeks
accessible. If `DOE_PDF_ARCHIVE_PATH` is lost, every week the DOE has since
replaced is unrecoverable. Confirm it is on persistent, backed-up storage
before production — this is the single highest-consequence infrastructure
detail in the system.

**Rate limits are per-IP and public.** 60/min will not stop a distributed
scrape. Acceptable for published government data; worth revisiting if the API
starts carrying anything else.

## Deployment checklist

Ordered. Stop on any failure.

- [ ] Snapshot the database
- [ ] Confirm `DOE_PDF_ARCHIVE_PATH` is on persistent, backed-up storage
- [ ] `php artisan fip:check-config --production` → exit 0
- [ ] `php artisan migrate --force`
- [ ] `composer install --no-dev -o && php artisan config:cache route:cache`
- [ ] Reload PHP-FPM
- [ ] `curl -sS https://fip.nelleeph.com/api/v1/health | jq .data.status` → `ok`
- [ ] `curl -sSI https://fip.nelleeph.com/api/health` → 200
- [ ] `cd frontend && npm ci && npm run build && pm2 reload fip-web`
- [ ] `curl -sSI https://fip.nelleeph.com/doe` → 200 *(currently 404)*
- [ ] Set `CORS_ALLOWED_ORIGINS` to the production web origin(s), HTTPS only
- [ ] Deploy the ingest; `python pipeline.py --dry-run` → sensible output
- [ ] `python pipeline.py --replay storage/pdfs/` to backfill `monitoring_date`
- [ ] Confirm via `/api/v1/fuel/reports` that every report now has a `monitoring_date`
- [ ] Wait for the 06:00 run; confirm `/admin/system` shows per-phase timings
- [ ] Confirm `graphql_pages` is 1

## Score, itemised

| Dimension | Score | Reason |
|---|---:|---|
| Correctness | 9 | Known data-quality defects fixed with regression tests; no open correctness bug |
| Test coverage | 8 | 283 tests across four stacks. The admin page and the live health path are untested end to end |
| Observability | 8 | Per-phase timings, health, operator dashboard — all built, none yet exercised |
| Security | 8 | TLS, HSTS, CSP, rate limiting verified live; config gate added. No pen test, no secret rotation policy |
| Operability | 7 | Two runbooks, replay path, kept originals. No alerting on a region going quiet |
| Deployment readiness | 7 | Deployed and verified end to end, but by rsync rather than from the shared remote |
| Data durability | 6 | Archive on the server, snapshotted nightly, restore proven. No off-site copy |
| **Overall** | **7** | Running and verified. The gaps left are operational, and named |
