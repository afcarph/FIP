# Staging data

How to take a fresh staging environment from an empty database to one where the
map, dashboard, advisor and forecasts all show something worth looking at.

Everything here is a documented command. Nothing needs a database client, and
nothing creates an account with a password anyone else knows.

---

## 1. Migrate and seed

```bash
docker compose -f infra/docker-compose.yml -f infra/docker-compose.staging.yml --env-file .env \
  exec -T api php artisan migrate --force

docker compose -f infra/docker-compose.yml -f infra/docker-compose.staging.yml --env-file .env \
  exec -T api php artisan db:seed --class=StagingSeeder --force
```

`StagingSeeder` runs four seeders in order:

| Seeder | What it puts there |
| --- | --- |
| `ReferenceDataSeeder` | regions, provinces, cities, brands, fuel types, payment methods |
| `RolePermissionSeeder` | 8 roles, 42 permissions |
| `GasStationSeeder` | 26 stations across Metro Manila, Central Luzon and CALABARZON |
| `StationPriceSeeder` | current prices for 7 fuel types, plus 16 weeks of weekly history |

**It deliberately does not run `DemoDataSeeder`.** That seeder creates users
whose passwords are in the repository — fine on a laptop, not on a box other
people can reach. Testers register their own accounts; this seeder exists so
there is something to see once they do.

### Why the prices go through the service

`StationPriceSeeder` calls `PriceService::recordPrice` rather than inserting
rows. `station_prices` holds only the *current* price per station and fuel type;
the series lives in `fuel_price_history`, and `recordPrice` is what writes both.
Seeding the table directly gives a map that looks populated and a forecast with
nothing to read.

---

## 2. Move prices forward

```bash
php artisan fip:import-doe                      # bundled sample, this week
php artisan fip:import-doe path/to/week.csv     # a real weekly adjustment
php artisan fip:import-doe --week=2026-08-10    # a specific week
php artisan fip:import-doe --dry-run            # parse and report, write nothing
```

CSV columns are `fuel_code`, `change_amount`, and an optional `notes`:

```csv
fuel_code,change_amount,notes
gasoline_ron95,0.80,Dubai crude firmer week on week
diesel,-0.35,Softer regional gasoil cracks
```

JSON works too, either as a bare list or under an `adjustments` key.

**Direction is derived from the sign**, not read from the file, so a row
claiming "increase" with a negative amount cannot record a contradiction.

**History is preserved.** The adjustment is stored as a `PriceAdvisory` and
applied through `PriceService`, which appends to `fuel_price_history` for every
station it touches. Re-importing the same week corrects that week's advisory
rather than stacking a second one.

### How this differs from `fip:import-advisories`

`fip:import-advisories` fetches the live DOE feed over HTTP and needs
`DOE_FEED_URL` set. It is the production path. It cannot run on a box with no
route to the feed, which is every fresh staging environment — hence the file
importer.

---

## 3. Generate forecasts

```bash
php artisan fip:forecast                  # next effective week
php artisan fip:forecast --week=2026-08-11
```

Forecasts are produced by the AI service from the price history seeded above, so
**run this after seeding**, not before. With no history the service has nothing
to extrapolate and returns a low-confidence "not enough data" answer.

The command exits non-zero if it produced nothing at all. That is deliberate —
it is scheduled work, and a silent success would hide an AI service that has
been down for a week.

---

## 4. Confirm it worked

```bash
php artisan tinker --execute="
  echo 'stations: '.App\Domain\Station\Models\GasStation::count().PHP_EOL;
  echo 'prices:   '.App\Domain\Pricing\Models\StationPrice::count().PHP_EOL;
  echo 'history:  '.App\Domain\Pricing\Models\FuelPriceHistory::count().PHP_EOL;
  echo 'forecasts:'.App\Domain\Ai\Models\PriceForecast::count().PHP_EOL;
"
```

Then, in the app:

| Surface | What should now be there |
| --- | --- |
| Map | station pins around Metro Manila, prices per fuel type |
| Stations | the directory, searchable by name or brand |
| Dashboard | national trend chart with 16 weeks of movement |
| Forecasts | a forecast per gasoline and diesel type |
| AI Advisor | answers that cite prices and this week's outlook |

---

## Re-running

`GasStationSeeder` is keyed on slug, so re-running adds nothing and overwrites
nothing.

`StationPriceSeeder` **stops** if `fuel_price_history` already has rows, and
says so. This is not laziness: `recordPrice` refuses a price older than the one
already stored, and a refused write records no history either — so a second run
would walk sixteen weeks of backdated prices past a newer current price and
report success having written nothing. Use `fip:import-doe` to move prices on
instead.

To start over:

```bash
php artisan migrate:fresh --force
php artisan db:seed --class=StagingSeeder --force
```

---

## Known limits

- The stations are a representative sample, not the national network.
  Coordinates are close to the real sites but are not survey data.
- Prices are synthetic. They move like DOE adjustments — mostly small weekly
  changes with the occasional larger swing — but they are generated from a fixed
  seed, not observed.
- `fip:refresh-indicators` needs `EXCHANGE_RATE_API_KEY`. Without it there are no
  market indicators, which lowers forecast confidence but no longer breaks the
  run.

---

## Live DOE prices

Everything above uses seeded or file-imported data, which is what a staging box
without a route to the DOE needs. Where there *is* a route, the ingest service
in [`doe-pdf-ingest/`](../doe-pdf-ingest/README.md) collects the department's
own weekly publications.

```bash
cd doe-pdf-ingest
cp .env.example .env          # point DOE_DB_* at the platform's database
docker compose up -d          # the 06:00 scheduler
docker compose run --rm ingest python pipeline.py --dry-run
```

It writes `fuel_reports`, `fuel_prices` and `doe_import_runs` — tables Laravel
creates and the ingest only fills. Nothing it writes touches `station_prices`
or `fuel_price_history`, because what the DOE publishes is not a station price:
it is a min-max range per city, product and brand, which the platform had no
table for until now.

Served by `/api/v1/fuel/latest`, `/history`, `/areas`, `/brands`, `/search`,
`/trends`, and `/imports` for the admin dashboard.
