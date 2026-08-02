# 5. API Documentation

Base URL: `https://api.fip.ph/api/v1` (locally `http://localhost:8000/api/v1`)

Interactive Swagger UI: `/api/documentation`. Machine-readable specification:
[`openapi.yaml`](openapi.yaml), or regenerate from source annotations with
`make docs`.

---

## 5.1 Conventions

### Response envelope

Every successful response has the same shape, so a client writes one parser:

```json
{
  "success": true,
  "message": "Prices updated.",
  "data": { },
  "meta": {
    "request_id": "3f2a9c1e-...",
    "timestamp": "2026-08-02T14:31:07+08:00"
  }
}
```

Paginated collections add `meta.pagination`:

```json
{
  "success": true,
  "data": [ ],
  "meta": {
    "pagination": {
      "current_page": 1, "per_page": 20, "total": 143,
      "last_page": 8, "from": 1, "to": 20
    }
  }
}
```

Errors are equally uniform:

```json
{
  "success": false,
  "error": {
    "code": "report_too_far",
    "message": "You are 1,240 m from the station; reports must be filed within 500 m.",
    "details": { "distance_m": 1240, "allowed_m": 500 }
  },
  "meta": { "request_id": "3f2a9c1e-..." }
}
```

**Always branch on `error.code`, never on the message.** Messages are written
for humans and will be reworded or translated; codes are contract.

### Error codes

| Code | Status | Meaning |
|------|--------|---------|
| `validation_failed` | 422 | `details` holds field → messages |
| `invalid_credentials` | 401 | deliberately identical for a wrong password and an unknown address |
| `account_locked` | 423 | too many failures; `details.locked_until` |
| `account_inactive` | 403 | suspended or banned |
| `mfa_challenge_expired` | 410 | restart the sign-in |
| `unauthenticated` | 401 | missing or expired token |
| `forbidden` | 403 | authenticated but not permitted |
| `cross_tenant_denied` | 403 | the resource belongs to another organisation |
| `not_found` | 404 | |
| `report_too_far` | 422 | outside the station geofence |
| `price_out_of_range` | 422 | too far from the local median |
| `duplicate_report` | 429 | already reported this station within the hour |
| `odometer_rollback` | 422 | reading below the previous one |
| `inconsistent_total` | 422 | total does not match litres × price |
| `edit_window_expired` | 403 | a driver amending an old fill-up |
| `implausible_price` | 422 | outside ₱0.01–₱999.99 |
| `ai_service_unavailable` | 503 | the AI service is down; retry |
| `rate_limited` | 429 | see `Retry-After` |
| `server_error` | 500 | `meta.request_id` identifies it in the logs |

### Authentication

```http
Authorization: Bearer <jwt>
```

Access tokens live 60 minutes; refresh tokens 14 days. `POST /auth/refresh`
exchanges a valid token for a fresh one. Both official clients serialise
refresh through a single in-flight promise so concurrent 401s cannot race.

### Rate limits

Keyed by user id when authenticated, otherwise by IP.

| Group | Limit | Applies to |
|-------|-------|------------|
| `auth` | 10/min | login, register, reset, biometric |
| `public` | 60/min | station and price browsing |
| `authenticated` | 120/min | everything else |
| `ai` | 20/min | assistant chat, route optimisation |
| `ocr` | 10/min | price-board scanning |
| `reports` | 10 per 5 min | report generation |

Responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a 429 carries
`Retry-After`.

### Request tracing

Send `X-Request-Id` and it is echoed back, embedded in error payloads and
written to the audit trail. Omit it and one is generated. Quote it in a
support request and the whole path through Nginx, Laravel and the AI service
can be reconstructed.

---

## 5.2 Endpoint reference

Endpoints marked 🔓 need no authentication.

### Authentication

| Method | Path | Notes |
|--------|------|-------|
| POST | `/auth/register` 🔓 | 12-character minimum, checked against known breaches |
| POST | `/auth/login` 🔓 | returns a session **or** an MFA challenge |
| POST | `/auth/mfa/verify` 🔓 | exchanges the challenge for a session |
| POST | `/auth/biometric/challenge` 🔓 | issues a single-use nonce |
| POST | `/auth/biometric` 🔓 | device signs the nonce with its private key |
| GET | `/auth/{google\|apple}/redirect` 🔓 | OAuth start |
| GET | `/auth/{google\|apple}/callback` 🔓 | OAuth completion |
| POST | `/auth/forgot-password` 🔓 | identical response whether or not the address exists |
| POST | `/auth/reset-password` 🔓 | |
| POST | `/auth/refresh` | |
| POST | `/auth/logout` | blacklists the token until natural expiry |
| GET | `/auth/me` | profile, roles and permissions |
| POST | `/auth/mfa/enrol` | returns the secret and provisioning URI |
| POST | `/auth/mfa/confirm` | returns recovery codes, shown once |
| DELETE | `/auth/mfa` | |

**Login returning an MFA challenge:**

```json
{
  "success": true,
  "data": {
    "status": "mfa_required",
    "challenge_token": "64-character opaque token",
    "expires_in": 300
  }
}
```

No access token is issued at this point. The challenge lives only in Redis for
five minutes, so a captured challenge is worthless after that.

### Prices 🔓

| Method | Path | Notes |
|--------|------|-------|
| GET | `/prices/fuel-types` | reference list |
| GET | `/prices/comparison` | min / average / max / spread per fuel type |
| GET | `/prices/trend` | daily national average series |
| GET | `/prices/advisories` | weekly DOE adjustment history |
| GET | `/prices/heat-map` | city-level aggregates for map shading |
| GET | `/prices/regional-movement` | week-on-week change by region |
| GET | `/stations/{station}/prices/history` | per-station series |
| PUT | `/stations/{station}/prices` | operator update (authenticated) |

### Stations 🔓

| Method | Path | Notes |
|--------|------|-------|
| GET | `/stations` | filter by brand, city, fuel type, amenities, payment methods |
| GET | `/stations/nearby` | radius search; each row carries `distance_km` |
| GET | `/stations/cheapest` | ranked by price then distance |
| GET | `/stations/{slug}` | full detail |
| POST | `/stations` | admin or station operator |
| PUT/DELETE | `/stations/{station}` | |
| POST | `/stations/{station}/rate` | 1–5 with an optional comment |

**`GET /stations/nearby`**

```
?latitude=14.5547&longitude=121.0244&radius_km=5&fuel_type_id=4
```

`radius_km` is capped at 50. Results are ordered nearest first; sort client-side
by price when that is what the screen needs.

### Forecasts 🔓

| Method | Path | Notes |
|--------|------|-------|
| GET | `/forecasts` | latest per fuel type, with drivers and narrative |
| GET | `/forecasts/history` | past forecasts scored against actual adjustments |
| GET | `/forecasts/accuracy` | MAE and direction accuracy over the trailing period |

```json
{
  "direction": "increase",
  "change_amount": 0.55,
  "confidence": 0.812,
  "confidence_label": "moderate",
  "narrative": "Regional gasoline cracks widened for a second week while the peso weakened to 58.15…",
  "drivers": [
    { "factor": "Regional product crack (MOPS)", "weight": 0.46, "value": "₱+0.38/L", "direction": "up" },
    { "factor": "Dubai crude", "weight": 0.28, "value": "₱+0.21/L", "direction": "up" }
  ]
}
```

`drivers` is ranked by contribution and sums to roughly 1. Show it — a forecast
a user cannot interrogate is a forecast they have no reason to believe.

### Vehicles, expenses and maintenance

| Method | Path | Notes |
|--------|------|-------|
| GET/POST | `/vehicles` | scoped to the caller's tenant |
| GET/PUT/DELETE | `/vehicles/{vehicle}` | |
| POST | `/vehicles/{vehicle}/odometer` | rejects a reading below the last |
| GET | `/vehicles/{vehicle}/efficiency` | trend and baseline deviation |
| GET/POST | `/expenses` | |
| PUT/DELETE | `/expenses/{purchase}` | drivers limited to a 48-hour window |
| GET | `/expenses/summary` | totals, monthly series and savings analysis |
| GET/POST | `/vehicles/{vehicle}/maintenance` | schedules, history and upcoming |
| POST | `/vehicles/{vehicle}/maintenance/predict` | refresh AI estimates |
| GET | `/maintenance/due` | across all the caller's vehicles |

**`POST /expenses`** derives `distance_since_last`, `km_per_litre` and
`cost_per_km` from the previous full-tank fill-up. Two validations bite before
anything is stored:

- `total_cost` must be within ₱1 or 2% of `litres × price_per_litre`
- `odometer` must not be below the previous reading

### Crowd sourcing and OCR

| Method | Path | Notes |
|--------|------|-------|
| GET | `/reports` 🔓 | published community reports |
| POST | `/reports` | geofenced to 500 m; price checked against the local median |
| POST | `/reports/{report}/vote` | ±1; three net downvotes flag a pending report |
| GET | `/reports/mine` | own submissions, with the caller's trust score |
| POST | `/ocr/scan` | multipart image upload |
| GET | `/ocr/scans` | scan history |

**`POST /ocr/scan`** returns per-line results, each with its own confidence and
a rejection reason where applicable:

```json
{
  "status": "needs_review",
  "overall_confidence": 0.91,
  "lines": [
    { "label": "XTRA UNLEADED 58.90", "fuel_type_code": "gasoline_ron91",
      "price": 58.90, "confidence": 0.94, "valid": true },
    { "label": "DIESEL 5.74", "price": 5.74, "confidence": 0.62,
      "valid": false, "rejection_reason": "price_out_of_local_band" }
  ]
}
```

A misplaced decimal point is caught by the band check rather than published.

### AI advisor

| Method | Path | Notes |
|--------|------|-------|
| POST | `/assistant/chat` | grounded in the caller's own data |
| GET | `/assistant/should-i-refuel` | deterministic — arithmetic, not the model |
| GET | `/assistant/consumption-explainer` | attributes a change to distance, price or efficiency |
| GET | `/assistant/sessions` | chat history |
| POST | `/routes/optimize` | ranked alternatives with fuel, toll and refuelling stop |

`/assistant/should-i-refuel` deliberately bypasses the language model. The
answer is arithmetic on the forecast and the user's tank, so it must be exact
and identical every time it is asked.

### Fleet

| Method | Path | Required role |
|--------|------|---------------|
| GET | `/fleet` | fleet manager or above |
| GET | `/fleet/dashboard` | " |
| GET | `/fleet/drivers` | " |
| POST | `/fleet/assignments` | " |
| GET | `/fleet/fraud-alerts` | " |
| PATCH | `/fleet/fraud-alerts/{alert}` | " |

### Reports and notifications

| Method | Path | Notes |
|--------|------|-------|
| GET | `/reports/definitions` | filtered by the caller's permissions |
| POST | `/reports/generate` | 201 inline, or 202 queued for large sets |
| GET | `/reports/runs/{run}` | status and a signed download URL |
| GET | `/notifications` | |
| PATCH | `/notifications/{id}/read` | |
| POST | `/notifications/devices` | register an FCM token |
| GET/POST/DELETE | `/price-alerts` | standing alert rules |

Report downloads are short-lived signed S3 URLs (15 minutes). The file itself
is never served through the API.

### Administration

| Method | Path | Permission |
|--------|------|-----------|
| GET | `/admin/moderation/queue` | `prices.moderate` |
| POST | `/admin/moderation/reports/{report}/{approve\|reject}` | `prices.moderate` |
| GET | `/admin/moderation/ocr` | `prices.moderate` |
| POST | `/admin/moderation/ocr/{scan}/{approve\|reject}` | `prices.moderate` |
| GET/POST/PUT/DELETE | `/admin/users` | `users.*` |
| GET | `/admin/roles` | `roles.manage` |
| PUT | `/admin/roles/{role}/permissions` | `roles.manage` |
| GET | `/admin/audit-logs` | `audit.view` |
| GET | `/admin/api-metrics` | `audit.view` |
| GET | `/admin/ai/models` | `ai.manage` |
| POST | `/admin/ai/forecast/run` | `ai.manage` |
| GET | `/dashboard/executive` | `analytics.platform` |

---

## 5.3 Worked example

Finding the cheapest diesel nearby and logging the fill-up:

```bash
API=http://localhost:8000/api/v1

# 1. Sign in
TOKEN=$(curl -s -X POST "$API/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@fip.ph","password":"Password123!"}' \
  | jq -r '.data.access_token')

# 2. Which fuel type is diesel?
DIESEL=$(curl -s "$API/prices/fuel-types" \
  | jq -r '.data[] | select(.code=="diesel") | .id')

# 3. Cheapest within 5 km of Makati
curl -s "$API/stations/cheapest?latitude=14.5547&longitude=121.0244&fuel_type_id=$DIESEL" \
  | jq '.data[0] | {name, price, distance_km}'

# 4. Should I fill up today, or wait?
curl -s "$API/assistant/should-i-refuel" -H "Authorization: Bearer $TOKEN" \
  | jq '{recommendation, headline, estimated_impact}'

# 5. Log the fill-up
curl -s -X POST "$API/expenses" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "vehicle_id": 1,
    "station_id": 4,
    "litres": 42.5,
    "price_per_litre": 57.20,
    "odometer": 51200,
    "is_full_tank": true,
    "purchased_at": "2026-08-02T09:15:00+08:00"
  }' | jq '.data | {km_per_litre, cost_per_km, distance_since_last}'
```

Step 5 returns the derived metrics immediately — they are computed at write
time, not on a later read.

---

## 5.4 Versioning

The version is in the path (`/api/v1`). Within a version:

- **Additive changes ship without notice.** New fields, new optional
  parameters, new endpoints. Clients must ignore unknown fields.
- **Breaking changes get a new version.** Removing or renaming a field,
  changing a type, tightening validation, or altering an error code.
- **Deprecation is announced.** Six months' notice, with a `Deprecation`
  header on affected endpoints and `Sunset` giving the removal date.

Adding a value to an enumeration is treated as additive — which is why
enumerations are stored as `VARCHAR` with a `CHECK` rather than as MySQL
`ENUM`.
