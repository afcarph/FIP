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

Production runs Docker Compose from `/opt/fip`. `--env-file .env` is not
optional: without it Compose cannot interpolate `DB_PASSWORD` and refuses to
start.

```bash
ssh staging
cd /opt/fip
git fetch origin <branch> && git merge --ff-only FETCH_HEAD

# The compose invocation, used for every command below.
DC="docker compose -f infra/docker-compose.yml -f infra/docker-compose.staging.yml --env-file .env"

# 1. Schema, before code. The ingest tolerates a missing column, not a missing table.
$DC exec -T api php artisan migrate --force

# 2. API. The backend is bind-mounted, so the code on disk is already live —
#    but its caches are not. See "Caches" below.
$DC exec -T api php artisan config:cache
$DC exec -T api php artisan route:cache

# 3. Web client. Not mounted: the staging overlay resets its volumes and builds
#    a production target, so a change reaches a browser only via a new image.
#    Then check the container really moved — see below.
$DC build web
$DC up -d web
```

### Check the web container is running the image you just built

`docker compose up -d web` after a `build` normally recreates the container and
picks up the new image. In a controlled test on this host it did exactly that:
image `50da8433` running, rebuilt to `9b689c4a`, and a plain `up -d web`
recreated the container onto the new digest.

It has, however, been observed once leaving the **old container running** after
a rebuild — the build succeeded, the command reported no error, and production
kept serving the previous bundle. The change looked deployed, so verifying it
tested the code it was supposed to have replaced. That cost an afternoon: a map
fix was investigated as broken when it had simply never shipped.

The cause of that one occurrence was not established, so treat the check rather
than the flag as the rule. Compose decides whether to recreate by comparing a
hash of the service *definition*, stored on the container as
`com.docker.compose.config-hash`, and its image bookkeeping can drift from
reality — on this host the container's `com.docker.compose.image` label and the
image it was actually running were two different digests.

So after every web deploy, compare the digests:

```bash
$DC build web && $DC up -d web
sleep 15
echo "image:     $(docker images --no-trunc -q fip-web | head -1)"
echo "container: $(docker inspect -f '{{.Image}}' fip-web-1)"
docker ps --filter name=fip-web --format '{{.Status}}'
```

The two must be identical. If they differ, the container is stale whatever the
deploy output said, and `$DC up -d --force-recreate web` will move it.

### Caches

The backend being bind-mounted makes code changes live instantly and makes two
kinds of change invisible until a cache is rebuilt:

| Changed | Rebuild | Symptom if skipped |
|---|---|---|
| A route (new endpoint, changed path or verb) | `php artisan route:cache` | The endpoint 404s with `not_found`, while the code plainly defines it |
| `.env` | `php artisan config:cache` | The file is read and ignored |
| Blade views | `php artisan view:cache` | Stale markup |

A new route 404ing after a deploy is not a routing bug and not a bad merge. It
is the route cache, every time.

### Verifying a deploy landed

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://fip.nelleeph.com/login          # 200
curl -s -o /dev/null -w '%{http_code}\n' https://fip.nelleeph.com/<new-route>    # 200
curl -s -o /dev/null -w '%{http_code}\n' https://fip.nelleeph.com/fleet/nope-xyz # 404
```

The third request is the one that matters: a Next.js route that exists answers
200 and one that does not answers 404, so the pair together distinguishes "the
page is deployed" from "everything answers 200".

Verifying a **page** rather than a route means opening it in a browser, and a
browser tab that is not in the foreground runs no `requestAnimationFrame` — a
map will render nothing at all, indefinitely, with every network request
succeeding. Record `document.visibilityState` beside any measurement taken from
an automated browser.

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
