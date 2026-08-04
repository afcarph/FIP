# 6. Deployment Guide

Target: a single Ubuntu 22.04 LTS host running Docker, behind Cloudflare.
The same images scale to multiple hosts without modification — see
[§6.8](#68-scaling-beyond-one-host).

---

## 6.1 Sizing

| Users | vCPU | RAM | Disk | Notes |
|-------|------|-----|------|-------|
| < 1k | 2 | 4 GB | 40 GB | development / pilot |
| < 10k | 4 | 8 GB | 80 GB | single host, comfortable |
| < 50k | 8 | 16 GB | 200 GB | add a read replica |
| > 50k | separate database host | | | see §6.8 |

Disk grows mainly from `fuel_price_history` and OCR images. Budget roughly
2 GB per 100k price observations and 500 KB per scan.

---

## 6.2 Host preparation

```bash
# System
sudo apt update && sudo apt upgrade -y
sudo apt install -y ca-certificates curl gnupg git ufw fail2ban

# Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
newgrp docker

# Firewall — nothing but SSH and HTTP(S) reaches the host
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable

# Manila time, so scheduled DOE work fires at the right moment
sudo timedatectl set-timezone Asia/Manila
```

Harden SSH before exposing the host:

```bash
sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/;
             s/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sudo systemctl restart ssh
```

---

## 6.3 Application deployment

```bash
sudo mkdir -p /opt/fip && sudo chown "$USER" /opt/fip
git clone https://github.com/afcarph/pmo.git /opt/fip
cd /opt/fip

cp .env.example .env
```

Generate strong secrets rather than inventing them:

```bash
# Database, Redis and the service token
sed -i "s/CHANGE_ME_DB_PASSWORD/$(openssl rand -base64 32 | tr -d '/+=')/"    .env
sed -i "s/CHANGE_ME_ROOT_PASSWORD/$(openssl rand -base64 32 | tr -d '/+=')/"  .env
sed -i "s/CHANGE_ME_REDIS_PASSWORD/$(openssl rand -base64 32 | tr -d '/+=')/" .env
sed -i "s/CHANGE_ME_SHARED_SERVICE_TOKEN/$(openssl rand -hex 32)/"            .env
```

Then edit `.env` by hand for the values only you know: the domain, the Google
Maps key, OAuth credentials, the OpenAI key and the FCM configuration. Set:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.fip.ph
FRONTEND_URL=https://fip.ph
SESSION_SECURE_COOKIE=true
CORS_ALLOWED_ORIGINS=https://fip.ph
```

Start and initialise:

```bash
make prod-build
docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml \
    --env-file .env up -d

docker compose ... exec api php artisan key:generate --force
docker compose ... exec api php artisan jwt:secret --force
docker compose ... exec api php artisan migrate --force
docker compose ... exec api php artisan db:seed --class=ReferenceDataSeeder --force
docker compose ... exec api php artisan db:seed --class=RolePermissionSeeder --force
```

Seed reference and role data, but **not** `DemoDataSeeder` — it creates
accounts with a published password.

Create the first real administrator:

```bash
docker compose ... exec api php artisan tinker
>>> $user = App\Domain\User\Models\User::create([
...     'first_name' => 'Ops', 'last_name' => 'Admin',
...     'email' => 'ops@yourdomain.ph',
...     'password' => 'a-long-unique-password',
...     'status' => 'active', 'email_verified_at' => now(),
... ]);
>>> $user->assignRole('super_admin');
```

Then enrol MFA on that account before doing anything else.

---

## 6.4 TLS

```bash
sudo apt install -y certbot python3-certbot-nginx

sudo certbot certonly --standalone \
    -d fip.ph -d www.fip.ph -d api.fip.ph \
    --email ops@yourdomain.ph --agree-tos --no-eff-email

cp infra/nginx/conf.d/fip.prod.conf.example infra/nginx/conf.d/fip.conf
# Replace the domain, then reload
docker compose ... restart nginx
```

Renewal, with a reload hook so Nginx picks up the new certificate:

```bash
echo '0 3 * * 1 certbot renew --quiet --deploy-hook "docker compose -f /opt/fip/infra/docker-compose.yml restart nginx"' \
    | sudo crontab -
```

Behind Cloudflare, set SSL mode to **Full (strict)** — "Flexible" leaves the
origin hop unencrypted, which defeats the point.

---

## 6.5 Scheduled work

The `scheduler` container runs `schedule:work` and needs nothing external.
Verify it is firing:

```bash
docker compose ... exec api php artisan schedule:list
```

| Job | When (Asia/Manila) |
|-----|--------------------|
| Refresh market indicators | daily 01:00 |
| Generate the weekly forecast | Monday 02:00 |
| Screen fleets for fraud | daily 02:30 |
| Refresh maintenance statuses | daily 03:00 |
| Import the DOE advisory | Tuesday 05:30 |
| Send maintenance reminders | daily 08:00 |
| Score last week's forecast | Wednesday 08:00 |

The ordering is deliberate: indicators refresh before the forecast that
consumes them, and the DOE import runs before reminders that might reference
the new prices.

Add the backup cron separately, on the host:

```bash
echo '0 2 * * * /opt/fip/infra/scripts/backup.sh >> /var/log/fip-backup.log 2>&1' \
    | crontab -
```

---

## 6.6 Routine deployments

```bash
cd /opt/fip
./infra/scripts/deploy.sh main
```

The script's ordering is the point: back up, then build, and only then stop
anything. A failed build leaves the running site untouched.

Rollback:

```bash
git checkout <previous-tag>
./infra/scripts/deploy.sh <previous-tag>

# If a migration must also be undone
docker compose ... exec api php artisan migrate:rollback --step=1
```

Write migrations so this is possible — every `up()` needs a real `down()`.

---

## 6.7 Monitoring

Minimum viable observability:

```bash
# Service health
curl -fsS https://api.fip.ph/api/health
curl -fsS http://localhost:8001/health | jq .capabilities

# Queue depth — sustained growth means workers cannot keep up
docker compose ... exec redis redis-cli -a "$REDIS_PASSWORD" LLEN queues:default

# Failed jobs
docker compose ... exec api php artisan queue:failed

# Slowest endpoints over the last day
curl -s https://api.fip.ph/api/v1/admin/api-metrics \
     -H "Authorization: Bearer $TOKEN" | jq '.data.endpoints[:5]'
```

The AI health endpoint reports *capabilities*, not just liveness — it tells
you whether a trained artefact is loaded and whether the language model is
configured, so a silent downgrade to the fallback path is visible.

Alert on:

| Signal | Threshold |
|--------|-----------|
| API health check | 2 consecutive failures |
| Queue depth | > 1000 sustained for 5 minutes |
| Failed jobs | > 50 in an hour |
| Disk | > 80% |
| MySQL connections | > 80% of `max_connections` |
| 5xx rate | > 1% over 5 minutes |
| Certificate expiry | < 14 days |

---

## 6.8 Scaling beyond one host

In the order pressure actually arrives:

**1. Read replica.** Already supported — set `DB_READ_HOST` and every `SELECT`
moves off the primary. `DB_READ_PORT` defaults to `DB_PORT`, for a replica
reached through a proxy or tunnel. Reads are sticky within a request that has
written, so a caller never observes replica lag on its own write. Leave it unset
on a single node and reads stay on `DB_HOST`.

Routing and stickiness are verified against a real replication pair (see
[§1](01-system-architecture.md#read-replica)); what a lab cannot tell you is how
far behind *your* replica runs under *your* load. Soak it in staging before
enabling in production:

| Check | How | What would stop the rollout |
|-------|-----|------------------------------|
| Steady-state lag | `SHOW REPLICA STATUS` → `Seconds_Behind_Source`, sampled for a full day | sustained above ~1s, or sawtoothing under normal traffic |
| Lag under the weekly peak | sample across the Tuesday DOE import and the Monday 02:00 forecast — the two heaviest writes | lag grows without recovering after the batch ends |
| Read-after-write | log a fill-up, then load the dashboard, as a client would | the fill-up is missing from the totals |
| Failover | stop the replica | reads must fall back or fail loudly, not hang |
| Replica saturation | watch CPU and IO on the replica while analytics run | the replica, not the primary, becomes the bottleneck |

Roll out behind the environment variable: `DB_READ_HOST` unset is the current
production behaviour, so enabling and reverting are both a single variable and
a restart. Watch the read-after-write case first — it is the one with a
user-visible failure mode rather than a purely operational one.

**2. Horizontal API.** `api` and `web` are stateless; raise `replicas` in the
production overlay. Sessions live in JWTs, so no sticky routing is required.

**3. AI service.** Scale independently — OCR is CPU-bound and bursty while the
API is I/O-bound and steady.

**4. Managed database.** Move MySQL to RDS or Cloud SQL; point `DB_HOST` at it
and drop the container.

**5. Object storage.** Replace MinIO with S3 proper; only `AWS_ENDPOINT`
changes.

Keep `scheduler` at exactly one replica at every stage. Two schedulers would
double-fire every cron job — including applying the weekly DOE adjustment
twice, which would corrupt every price in the country.

---

## 6.9 Troubleshooting

**Containers restart in a loop**
```bash
docker compose ... logs --tail=100 api
```
Usually a missing `APP_KEY` or an unreachable database.

**`SQLSTATE[HY000] [2002] Connection refused`**
MySQL is still starting. The health check should prevent this; if it recurs,
raise `start_period`.

**Queue jobs are not processed**
```bash
docker compose ... ps queue
docker compose ... exec api php artisan queue:restart
```
`queue:restart` is required after any deploy — workers hold the old code in
memory until told to exit.

**Forecasts stop appearing**
```bash
curl -s http://localhost:8001/health | jq .capabilities
docker compose ... exec api php artisan fip:forecast
```
If `trained_booster` is false the service is on its fallback model. That is
degraded, not broken — forecasts still generate, with lower confidence.

**Disk filling**
```bash
docker system prune -a --volumes    # careful: removes unused volumes
du -sh /var/lib/docker/volumes/*
```
Check that partition pruning is running on `fuel_price_history`.
