# 2. Database Schema

MySQL 8.0, InnoDB, `utf8mb4_0900_ai_ci`. The canonical DDL is
[`database/schema.sql`](../database/schema.sql); the Laravel migrations under
`backend/database/migrations/` express the same structure through the schema
builder so CI and production converge.

---

## 2.1 Conventions

| Convention | Rule | Why |
|-----------|------|-----|
| Primary key | `id BIGINT UNSIGNED AUTO_INCREMENT` | uniform joins, no composite-key surprises |
| Money | `DECIMAL(10,4)` | PH pump prices carry 2–4 decimals; floats accumulate error across a million rows |
| Coordinates | `DECIMAL(10,7)` + generated `POINT` | 7 decimals ≈ 11 mm, more than enough; the POINT enables spatial indexing |
| Timestamps | `created_at` / `updated_at` on every mutable table | |
| Soft deletes | `deleted_at` on business entities | a deleted vehicle's fuel history must survive for reporting |
| Enumerations | `VARCHAR` + `CHECK` | adding a value is a data change, not a migration |
| Foreign keys | always explicit, named `fk_<table>_<column>` | orphan rows are a data-quality failure, not an optimisation |
| IP addresses | `VARBINARY(16)` | stores IPv4 and IPv6 in one column via `INET6_ATON` |

### Why `DECIMAL(10,4)` and not `DECIMAL(10,2)`

Retail boards quote two decimals, but DOE advisories and MOPS conversions
carry four. Rounding at storage time and again at display time compounds:
₱0.0025 per litre across a 200-litre truck fill is ₱0.50, and across a fleet's
annual consumption it is a visible discrepancy in a reconciliation report.

---

## 2.2 Table groups

### Geography and tenancy (6 tables)

`regions → provinces → cities` is a strict hierarchy; `companies` and `users`
both reference `cities`. Regional price analytics join up this chain, which is
why `fuel_price_history` denormalises `region_id` — the alternative is a
four-table join on every point of a six-month chart.

### Identity and access (10 tables)

`users`, `user_preferences`, `user_devices`, `oauth_accounts`,
`password_reset_tokens`, `login_attempts`, `jwt_blacklist`, plus the four
Spatie permission tables.

Notable columns:

- `users.mfa_secret VARBINARY(512)` — AES-256-GCM ciphertext, never plaintext.
- `users.mfa_recovery_codes VARBINARY(2048)` — encrypted JSON of *bcrypt
  hashes*. Two layers, because a recovery code is a password equivalent.
- `user_devices.biometric_key TEXT` — the device's **public** key only. A
  database leak cannot forge a biometric login.
- `users.failed_login_attempts` / `locked_until` — lockout state lives on the
  row so it survives a cache flush.

### Stations and prices (12 tables)

The heart of the system.

```
gas_stations ──┬── station_prices        (one live row per station × fuel)
               ├── fuel_price_history    (append-only, partitioned monthly)
               ├── price_reports         (crowd submissions + moderation)
               ├── ocr_scans             (photographed boards)
               ├── station_hours / photos / ratings
               └── station_amenity, station_payment_method  (many-to-many)
```

`station_prices` carries `UNIQUE (station_id, fuel_type_id)` — the invariant
that there is exactly one current price per pair. Everything else about price
history lives in `fuel_price_history`.

`change_amount` is a generated stored column:

```sql
change_amount DECIMAL(10,4)
  GENERATED ALWAYS AS (price - COALESCE(previous_price, price)) STORED
```

Generated rather than application-computed so it cannot drift from the two
columns it derives from, and stored rather than virtual so it can be indexed.

### Vehicles and fleets (9 tables)

A vehicle belongs to either a private owner (`owner_id`) or a company
(`company_id`). Both are nullable; the application enforces that at least one
is set.

`vehicles` has `UNIQUE (plate_number, deleted_at)`. This is a deliberate trick:
NULL is not equal to itself in a unique index, so many soft-deleted rows may
share a plate while only one live row can hold it. Selling a car and
re-registering the same plate later then works without a partial index.

### Expenses and trips (3 tables)

`fuel_purchases` stores both the raw entry and the derived metrics
(`distance_since_last`, `km_per_litre`, `cost_per_km`). Deriving at write time
rather than at read time means the dashboard, the exports and the AI features
all see identical numbers, and reporting queries stay cheap.

### Maintenance (3 tables)

`maintenance_schedules` holds two independent clocks — `interval_days` and
`interval_km` — plus `predicted_due_at` from the model. The rule-based date
remains contractual; the prediction is advisory and drives early reminders.

### AI artefacts (6 tables)

`ai_models` is a registry with metrics and a training row count, so an
administrator can compare versions before promoting one.

`price_forecasts` is the interesting table: it stores the prediction *and*,
once the DOE announces, the `actual_change` and `absolute_error`. That
back-fill is what makes the published accuracy figure verifiable rather than
marketing.

### Notifications and reporting (6 tables)

`notifications` uses a UUID primary key because it is high-volume and
append-heavy; a UUID avoids hot-spotting on the auto-increment.

### Audit (3 tables)

`audit_logs` has no `updated_at` and the model blocks `updating` and
`deleting`. `api_request_logs` is sampled at 5% for successful reads and 100%
for writes and errors — full logging of read traffic would dwarf the business
data.

---

## 2.3 Indexing strategy

Indexes exist for specific queries, not speculatively.

| Index | Serves |
|-------|--------|
| `gas_stations (latitude, longitude)` | bounding-box prune before the spatial predicate |
| `SPATIAL gas_stations (location)` | exact radius search |
| `station_prices (fuel_type_id, price)` | "cheapest diesel" ranking |
| `fuel_price_history (station_id, fuel_type_id, recorded_on)` | per-station history chart |
| `fuel_price_history (region_id, fuel_type_id, recorded_on)` | regional aggregation |
| `fuel_purchases (vehicle_id, purchased_at)` | the previous-full-tank lookup on every write |
| `fuel_purchases (anomaly_score)` | the fraud queue |
| `maintenance_schedules (due_at, status)` | the nightly reminder sweep |
| `price_reports (status, created_at)` | the moderation queue, oldest first |
| `notifications (user_id, read_at, created_at)` | the notification centre with its unread badge |

The composite orders follow the leftmost-prefix rule: `(vehicle_id,
purchased_at)` serves both "all fill-ups for a vehicle" and "the most recent
one", which is the query `FuelExpenseService` runs on every single write.

---

## 2.4 Reporting views

Three views keep the heaviest aggregations out of application code:

- **`v_station_current_prices`** — the live price of every fuel at every active
  station, denormalised with brand, city and region.
- **`v_regional_price_summary`** — min, average, max and station count per
  region per fuel type.
- **`v_vehicle_efficiency`** — lifetime totals and averages per vehicle.

They are views rather than materialised tables because the underlying data
changes constantly and a stale price is worse than a slightly slower query.

---

## 2.5 Partitioning

`fuel_price_history` is range-partitioned on `YEAR(recorded_on) * 100 +
MONTH(recorded_on)`:

```sql
PARTITION BY RANGE (YEAR(recorded_on) * 100 + MONTH(recorded_on)) (
  PARTITION p202601 VALUES LESS THAN (202602),
  ...
  PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

Two benefits. A 90-day query touches three partitions instead of the whole
table. And retention becomes `ALTER TABLE ... DROP PARTITION`, which is
instant, rather than a `DELETE` that would lock the table for hours.

Add partitions ahead of time — a month landing in `p_future` still works but
loses the pruning benefit:

```sql
ALTER TABLE fuel_price_history REORGANIZE PARTITION p_future INTO (
  PARTITION p202609 VALUES LESS THAN (202610),
  PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

---

## 2.6 Data retention

| Data | Retention | Basis |
|------|-----------|-------|
| `audit_logs` | 365 days | compliance and dispute resolution |
| `api_request_logs` | 30 days | operational only |
| `login_attempts` | 90 days | brute-force forensics |
| `fuel_price_history` | 5 years | model training needs long series |
| `report_runs` files | 30 days | regenerable on demand |
| `notifications` | 180 days | |
| Soft-deleted rows | 90 days, then purged | recovery window |
