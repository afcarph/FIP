# DOE price monitoring — UAT build

Two thin clients over the existing Laravel `/api/v1/fuel/*` endpoints, built for
user acceptance testing rather than production. No mock data: every figure on
every screen came from staging at `http://54.179.40.243/api/v1` while these
screenshots were taken.

Staging held, at the time of testing:

| | |
|---|---|
| Reports | 6 |
| Price records | 3,393 |
| Regions | NCR, REGIONS 6-8 |
| Coverage | 14 Jul – 10 Aug 2026 |
| Last run | `no_changes`, 7 Aug 2026 08:48, 62s |

## Running it

Web:

```bash
cd frontend && npm run dev
```

`frontend/.env.local` sets `NEXT_PUBLIC_API_URL`. It is git-ignored; point it at
whichever host you are testing.

Mobile:

```bash
cd mobile && flutter run --dart-define=API_BASE_URL=http://54.179.40.243/api/v1
```

The build-time value is only the default — Settings can repoint the app at
another host without a rebuild, which is the point of it being there.

## Screens

| Web | Mobile |
|---|---|
| Dashboard `/doe` | Home |
| Search `/doe/search` | Search |
| History `/doe/history` | History |
| API Status `/doe/status` | Settings |

Both Dashboard and Home carry a **Data freshness** panel: for each region, the
week its newest report covers, how old that is, and whether it is current. Age
is counted from the end of the covered week, not from when the report was
published or imported — a report imported this morning that covers three weeks
ago is three weeks old, and measuring from the import would call it fresh. A
region holding only last week is still "Current", because the DOE posts the
running week partway through it; two missed publications is not.

The panel also says out loud when one region trails another, which is the
current state on staging and the reading most likely to be filed as a bug in
the numbers.

Screenshots are in [`screenshots/`](screenshots).

Every screen distinguishes four states: loading, empty, offline, and the API
answering with an error. The last two are separated deliberately — the remedy
differs, and a tester needs to be able to say which one they saw.
`screenshots/mobile-api-failure.png` is the API-error state, produced by
pointing Settings at a bad path on a reachable host.

## Issues found during testing

### Fixed in this pass

**The first launch of a fresh install reported the API as broken.** Every
request stamps `X-Device-Id`, so the first screen called `deviceUuid()` several
times at once; each call found nothing stored and raced to write it. On iOS the
concurrent keychain write throws, Dio reported it as an unknown error with no
message, and the screen said the API had failed. It reproduced only on a wiped
simulator, which is why it survived earlier testing. Fixed in `50bc7c1`, with a
test that fails without the fix.

**Neither platform could reach staging at all.** Staging serves plain HTTP on an
IP: iOS ATS and Android cleartext policy both block that by default. Exceptions
are now scoped to that one host, in `ios/Runner/Info.plist` and
`android/app/src/main/res/xml/network_security_config.xml`, each with a note to
delete it once staging has TLS. **Staging has no TLS — credentials and tokens
cross the wire in plaintext. It should not be used with real accounts.**

**Trend charts smoothed between weeks.** Both clients drew monotone curves
through weekly points, which renders prices for days the DOE never published.
Now straight segments.

**"1,133 in the current week" was wrong.** `records_total` counts the newest
report held per region, and the regions are not always on the same week — NCR
currently lags Regions 6-8 by one publication. Relabelled.

**Reset in mobile Settings reloaded only some screens**, leaving the rest
showing the previous host's data while the header quietly recovered.

### Open — backend, not fixed here

Per the brief, backend endpoints were not modified except where a bug was
found. These are recorded rather than changed.

**`monitoring_date` is null for every REGIONS 6-8 report.** All six NCR-format
reports carry it; all three Visayas-format ones do not. The History table shows
this as `—`. The date is present in the source PDFs, so this is an extraction
gap in the Visayas layout, not missing source data.

**No NCR report exists for the 4–10 Aug week**, while REGIONS 6-8 has one. The
dashboard therefore headlines a week that only one region covers — the Data
freshness panel now names this explicitly rather than leaving it to be inferred
from two coverage labels. Whether the
DOE has not published it or discovery missed it is not resolved — worth a look
before UAT sign-off, because "latest" reading differently per region is exactly
the sort of thing a tester will report as a bug.

**Phase timings are not recorded.** `doe_import_runs` stores one total duration,
so the API Status page can only show "1m 2s" for discovery, extraction and
import combined. The page says so rather than implying otherwise. Already
deferred to a later iteration.

**North Luzon and Southern Luzon LPG documents are rejected every run.** Both
appear in `last_run.errors` and will keep appearing until those layouts are
supported. They are per-document rejections, not run failures, and no longer
mark the run unhealthy — the single `failed` row in Recent runs (7 Aug 08:42)
predates that fix and is historical.

**`errors` carries per-document rejections.** The field name reads as run-level
failure; the UI relabels it "Last run messages" and explains the distinction,
but the API field name still invites the wrong reading.

### Environment change made to staging

`CORS_ALLOWED_ORIGINS` was extended with `http://localhost:3000` and
`http://127.0.0.1:3000` so a locally-run web client can reach the staging API.
Without it every request failed at the browser with no useful error.

## Scope

This is a UAT surface, not a product. It has no authentication, no offline
cache, no per-station data — the DOE publishes ranges per city, brand and
product, and no station-level figures exist to show.
