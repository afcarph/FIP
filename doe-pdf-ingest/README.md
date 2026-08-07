# FIP DOE fuel price scraper

Collects Philippine retail fuel prices from the Department of Energy's Looker
Studio dashboard and stores them for the Fuel Intelligence Platform.

The dashboard is **not** read visually. Looker's own frontend asks its backend
for the data; this service runs that frontend in a real browser and reads the
JSON answers. There is no `query_selector`, no `inner_text`, no table walk, no
xpath and no OCR anywhere in this project — the `batchedDataV2` payload is the
only source of truth.

---

## How it works

```
Playwright opens the dashboard
   ↓  the page's own JavaScript issues getSchema and batchedDataV2
   ↓  every response is intercepted and decoded
   ↓  the )]}' guard is stripped, the body is parsed as JSON
   ↓  getSchema gives internal id → published label
   ↓  labels are matched to our columns (nothing hardcoded)
   ↓  dataResponse → dataSubset → tableDataset
   ↓  parallel typed columns + null mask → records
   ↓  MySQL, keyed on (station, price_date)
```

### Why a browser at all

The `batchedDataV2` request is signed with a token the page mints at load, and
the report, page and tile ids are regenerated whenever the DOE republishes.
Replaying a captured request works until the next dashboard edit. A browser
that renders the dashboard normally produces correct requests by construction.

### Why nothing is hardcoded

Looker names fields `qt_fgaojmiemc`, `qt_85e4fhiemc` — generated ids, reissued
every time the report is edited. A scraper pinned to them does not fail loudly
when the DOE republishes; it keeps running and writes nothing.

So the ids are resolved at run time:

```
qt_85e4fhiemc  ──getSchema──▶  "Gas Station"  ──patterns──▶  station
(per report)                   (published)                   (ours)
```

The label is the stable half — it is what the DOE shows the public. The
patterns that recognise it live in `config.py`.

### The null mask

This is the part worth knowing about. Looker does not send rows; it sends
parallel typed column arrays plus a positional null mask. A column with nulls
carries **fewer values than there are rows**, because omitted entries are
recorded in `nullIndex` rather than sent as null.

Zipping values by position without re-inserting the gaps shifts every
subsequent value up a row. The result is a complete, plausible, entirely wrong
dataset — every price is real, and attached to the wrong station. Nothing
downstream can detect it. `parser.expand_nulls` is what prevents it, and it has
its own tests.

---

## Quick start

```bash
cp .env.example .env      # then set DOE_DASHBOARD_URL and the database password
docker compose up -d
```

That brings up MySQL and the scheduler. The scheduler creates its schema on
start and waits for 06:00 Asia/Manila.

To run once, immediately:

```bash
docker compose run --rm scraper python scraper.py
```

### Without Docker

```bash
python3.12 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/playwright install chromium --with-deps
cp .env.example .env
.venv/bin/python scraper.py --dry-run
```

---

## Commands

```bash
python scraper.py                     # one run
python scraper.py --dry-run           # parse and report, write nothing
python scraper.py --replay captures/  # re-parse a capture, no browser
python scraper.py --headed            # watch it
python scraper.py --url https://...   # override the dashboard URL

python scheduler.py                   # long-lived, fires at 06:00
python scheduler.py --run-now         # and once immediately
```

`--replay` is the one to reach for when a run parses to zero rows. Captures are
written to `captures/<date>/` on every run and kept for 14 days, so a schema
change can be diagnosed in minutes rather than by waiting for tomorrow.

---

## Configuration

Every setting is an environment variable with a `DOE_` prefix. See
[.env.example](.env.example) for the full list. The two with no sensible
default:

| Variable | Why |
| --- | --- |
| `DOE_DASHBOARD_URL` | The report id changes when the DOE rebuilds the dashboard |
| `DOE_DB_PASSWORD` | — |

---

## Database

The scraper writes to its **own database** (`fip_doe`), not the platform's.

This is not a preference. FIP's Laravel migrations already own a table called
`fuel_price_history`, and it is a different table:

| | FIP's | This one |
| --- | --- | --- |
| grain | one row per fuel type | one row per station-date |
| columns | `station_id, fuel_type_id, price, recorded_on` | `station_id, price_date, ron91…diesel_plus` |

Sharing a schema would mean two owners for one name. See
[the bridge](#feeding-the-platform) for how rows get from here into the
platform's model.

### Tables

**`fuel_stations`** — a retail site as the DOE publishes it. The DOE issues no
station identifiers, so identity is a `fingerprint`: a hash of company, name,
city and barangay, normalised for case. Matching on the display name alone
merges the four different Petron sites in one city into one row.

**`fuel_price_history`** — one station's posted prices on one date. Unique on
`(station_id, price_date)`. Rows are never deleted and never rewritten with
equal values.

**`scraper_logs`** — one row per execution, including the ones that failed
before they started parsing. A log that only records successes cannot answer
"when did this last work?", which is the only question asked of it during an
incident.

### Import rules

- **Idempotent.** Re-running a day writes nothing.
- **Only changed prices are written.** Rewriting equal values daily would make
  `updated_at` useless as a signal and produce a binlog the size of the table
  for no information.
- **History is kept forever.** The only mutation permitted is correcting a
  price the DOE itself restated for a date already recorded.
- **A missing grade stays missing.** Almost no station sells all six, and a
  zero would be indistinguishable from "free" in an average.

---

## Feeding the platform

The scraper's tables are a landing zone. The platform's own model — the one the
map, the dashboard, the forecast and the advisor read — is populated by a
Laravel command:

```bash
php artisan fip:sync-doe-prices             # the most recent scraped date
php artisan fip:sync-doe-prices --dry-run
php artisan fip:sync-doe-prices --date=2026-08-06
php artisan fip:sync-doe-prices --create-missing
```

It writes through `PriceService::recordPrice`, so `fuel_price_history` gains a
row rather than `station_prices` being overwritten. Writing the table directly
would give a populated map and a forecast with nothing to read.

Station matching is deliberately conservative: brand **and** city must agree
before a name is considered. Unmatched stations are reported rather than
guessed at — a wrong match writes one station's price onto another, which is
worse than no match, because it cannot be seen.

`ron100` is not synced. The platform has no RON 100 fuel type, and mapping it
onto RON 97 would file one product's price under another.

Laravel reads the scraper's database over a separate `doe` connection —
`DOE_DB_HOST`, `DOE_DB_DATABASE`, `DOE_DB_USERNAME`, `DOE_DB_PASSWORD` in the
backend's `.env`.

---

## API

Seven public endpoints, served by the Laravel app from the scraped data:

```
GET /api/v1/fuel/latest              most recent prices + national statistics
GET /api/v1/fuel/history             the series, with a trend
GET /api/v1/fuel/stations            the directory
GET /api/v1/fuel/search              everything, filtered
GET /api/v1/fuel/company/{company}
GET /api/v1/fuel/city/{city}
GET /api/v1/fuel/compare?stations=12,48
GET /api/v1/fuel/insights            rankings, extremes, trends, movement
```

`search` accepts `province`, `city`, `municipality`, `barangay`, `station`,
`company`, `fuel`, `min_price`, `max_price`, `date` and `sort`.

`insights` returns the lowest, highest and average price, province and city
rankings, the cheapest and most expensive station, a trend series, and weekly
and monthly changes.

These sit alongside the platform's existing `/prices` and `/stations`, they do
not replace them. The distinction is provenance: `/stations` is the platform's
directory, which operators maintain and users report against. `/fuel` is what
the DOE published, unedited.

---

## Tests

```bash
pytest                     # 96 tests, under a second
ruff check . && ruff format --check .
```

No browser is needed: every test runs against captured payloads or in-memory
SQLite. The Docker job in CI is what proves a browser starts, which is the one
thing these cannot cover.

The tests worth reading first are `TestExpandNulls` and
`test_nulls_land_on_the_right_rows` in `tests/test_parser.py`. They cover the
failure that produces wrong data rather than no data.

---

## Operations

**Schedule.** 06:00 Asia/Manila, daily. The DOE publishes its weekly adjustment
early Tuesday; running daily rather than weekly means one failure costs one day.

**Retries.** Three attempts, 5s then 10s apart. Linear rather than exponential:
the dashboard is slow, not rate-limiting us, and a run that must finish before
the morning window closes cannot afford a doubling backoff.

**Cron instead.** See [crontab.example](crontab.example). Note it sources `.env`
explicitly and sets `TZ` — cron runs with a near-empty environment, and a bare
`python scraper.py` finds no database and runs at 2pm Manila time.

**Health.** `scraper_logs` is the record. A `partial` status means rows were
written and some tiles failed; `failed` means nothing was written.

### When a run parses zero rows

1. Check `scraper_logs.errors` for the run.
2. Look at `captures/<date>/` — the raw payloads are there.
3. `python scraper.py --replay captures/<date>/` to re-parse without a browser.
4. If the field mapping is unusable, the dashboard's labels have changed. The
   captured `getSchema` payload shows the new ones; the patterns are in
   `config.py`.

A mapping that resolves no station or no price column fails the run rather than
importing rows of nothing.

---

## Layout

| File | |
| --- | --- |
| `settings.py` | environment: credentials, hosts, timeouts |
| `config.py` | the Looker protocol and the label vocabulary |
| `interceptor.py` | response capture, `)]}'` stripping, JSON decoding |
| `mapper.py` | getSchema → id → label → column |
| `parser.py` | tableDataset → records, including the null mask |
| `models.py` | SQLAlchemy schema |
| `database.py` | engine, sessions, the import rules |
| `scraper.py` | orchestration, retries, CLI |
| `scheduler.py` | the 06:00 job |
| `logger.py` | logging, run correlation, payload capture |
