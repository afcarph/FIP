# Deployment

Target for RC1: a single host running the API, the web client and the ingest
cron, against a managed MySQL instance.

Staging today: `https://fip.nelleeph.com` → `54.179.40.243`.

## Before anything else

```bash
cd backend && php artisan fip:check-config --production
```

Exit code 1 means do not deploy. It checks the things that are invisible when
wrong: `APP_DEBUG` left on, a placeholder `APP_KEY`, a wildcard CORS origin
(also silently invalid alongside credentials), a plain-HTTP `APP_URL` that
password-reset links would be built from, an unwritable PDF archive.

## Environment

Backend `.env` — the values that matter for RC1:

| Key | Production value | Consequence if wrong |
|---|---|---|
| `APP_ENV` | `production` | Debug pages, verbose errors |
| `APP_DEBUG` | `false` | Stack traces expose environment variables |
| `APP_KEY` | generated, unique per environment | A staging session cookie is valid in production |
| `APP_URL` | `https://fip.nelleeph.com` | Mailed links downgrade to HTTP |
| `CORS_ALLOWED_ORIGINS` | the web origins, comma-separated, HTTPS | Browser clients refused, or any site can call the API with credentials |
| `SESSION_SECURE_COOKIE` | `true` | Session cookies sent over plain HTTP |
| `DOE_PDF_ARCHIVE_PATH` | absolute, writable, on persistent disk | Originals lost, so an extractor fix cannot be re-run |
| `DOE_SCHEDULER_STALE_AFTER_HOURS` | `26` | Health check either never fires or fires daily |
| `NEXT_PUBLIC_MAP_STYLE_URL` | MapTiler style URL | The map falls back to a keyless low-detail development basemap and says so on screen |

Ingest `.env` (`doe-pdf-ingest/`): database DSN, `GRAPHQL_ENDPOINT`,
`LOOKBACK_DAYS`, `GRAPHQL_MAX_PAGES`, `PDF_ARCHIVE_PATH`. The archive path must
be the same directory the API reports on, or the health endpoint describes a
directory nobody writes to.

## Order of operations

Migrations before code, because the ingest and the API deploy separately and
the ingest tolerates a missing column but not a missing table.

```bash
# 1. Schema
cd backend && php artisan migrate --force

# 2. API
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php-fpm

# 3. Web client
cd ../frontend && npm ci && npm run build && pm2 reload fip-web

# 4. Ingest
cd ../doe-pdf-ingest && pip install -r requirements.txt
```

`config:cache` must be re-run on every deploy that changes `.env`; a cached
config silently ignores the file.

## The cron

```cron
# 06:00 Asia/Manila. The DOE publishes mid-morning; this runs before that and
# picks the day's publication up on the following run rather than polling.
0 6 * * * cd /srv/fip/doe-pdf-ingest && /srv/fip/doe-pdf-ingest/.venv/bin/python pipeline.py >> logs/cron.log 2>&1
```

A run that imports nothing is a success — see [runbooks/import.md](runbooks/import.md).

## TLS

Already in place on staging and required in production:

- Let's Encrypt certificate, auto-renewing
- HTTP → HTTPS 301
- `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload`
- `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`

Verify after any proxy change:

```bash
curl -sSI https://fip.nelleeph.com/api/v1/fuel/imports | grep -iE "strict-transport|content-security|x-frame"
```

The mobile clients no longer carry cleartext exceptions for the API host. If
the API is ever served over plain HTTP again, both apps will fail every request
with no obvious cause — the exception was removed deliberately and should not
be re-added without TLS being genuinely unavailable.

## Health and rollback

| Probe | Purpose | Expected |
|---|---|---|
| `GET /api/health` | Liveness. Does not touch the database | 200 |
| `GET /api/v1/health` | Readiness: database, scheduler, disk, archive | 200 ok/degraded, 503 down |
| `GET /api/v1/admin/system` | Operator detail (authenticated) | 200 |

Point the load balancer at `/api/health` for liveness and `/api/v1/health` for
readiness. Do not use the readiness probe for liveness: a database blip would
restart otherwise healthy containers.

Rolling back code does not roll back a migration. The RC1 migration is additive
and nullable, so an older API runs against the new schema unchanged; roll code
back first and only reverse the migration if the schema itself is the fault.

Recovering data after a bad import is in
[runbooks/recovery.md](runbooks/recovery.md).
