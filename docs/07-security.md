# 7. Security

Threat model, controls and verification. Everything here maps to a specific
implementation; where a control is partially implemented it says so rather
than claiming coverage it does not have.

---

## 7.1 What we are protecting

Ranked by consequence rather than by likelihood:

| Asset | If compromised | Primary control |
|-------|----------------|-----------------|
| Fleet fuel and route data | a competitor learns a logistics operator's cost base and routes | tenant scoping at query, policy and middleware level |
| User credentials | account takeover, and reuse elsewhere | bcrypt cost 12, breach checking, MFA, lockout |
| Price integrity | poisoned prices send drivers to the wrong station; the product's core claim fails | geofence, band check, source precedence, moderation |
| Personal location history | movement patterns of identifiable people | coarse storage, retention limits, no third-party sharing |
| Administrative access | total platform compromise | role separation, mandatory MFA, immutable audit log |

The third is unusual and worth dwelling on. Most platforms treat data
*integrity* as secondary to confidentiality. Here it is primary: a competitor
reading our prices loses us little, but an attacker writing false prices
destroys the reason anyone uses the product.

---

## 7.2 OWASP Top 10 (2021)

### A01 — Broken access control

Authorisation is enforced at four independent layers, so a mistake at one does
not become a breach.

| Layer | Mechanism | Code |
|-------|-----------|------|
| Route | permission middleware | `routes/api.php` |
| Request | tenant guard | `EnsureCompanyScope` |
| Query | tenant scope | `HasCompanyScope::forUser()` |
| Record | policy | `app/Policies/*` |

The query-level scope is the one that actually prevents cross-tenant leaks,
because it applies whether or not a developer remembered the policy:

```php
public function scopeForUser(Builder $query, ?User $user): Builder
{
    if ($user === null) {
        return $query->whereRaw('1 = 0');   // fail closed
    }

    if ($user->isPlatformAdministrator()) {
        return $query;
    }

    if ($user->company_id !== null) {
        return $query->where($this->getTable().'.company_id', $user->company_id);
    }

    return $query->where($this->ownerColumn(), $user->getKey());
}
```

A null user yields no rows rather than all rows. Failing closed is the whole
point.

**Privilege escalation** is blocked explicitly: `UserAdminController::assignableRoles()`
strips `super_admin` and `system_admin` from any role assignment made by
someone who is not a super administrator. Without it, a system administrator
could mint themselves a super administrator account.

*Verified by* `tests/Feature/VehicleAuthorizationTest.php` — six tests covering
own-vehicle visibility, cross-tenant denial, driver deletion rights and
administrator override.

### A02 — Cryptographic failures

| Data | At rest | In transit |
|------|---------|------------|
| Passwords | bcrypt, cost 12 | TLS 1.2/1.3 |
| MFA secrets | AES-256-GCM via `Crypt` | " |
| MFA recovery codes | bcrypt hashes, then AES-encrypted | " |
| Biometric keys | **public key only** — no secret stored | " |
| Session tokens | HS256 JWT, 60-minute TTL, blacklisted on logout | " |
| Uploads and reports | S3 server-side encryption, private ACL | signed URLs, 15-minute expiry |

Recovery codes get two layers because a recovery code is a password
equivalent: bcrypt so a database dump does not yield usable codes, and
encryption so the hashes are not even offline-attackable without the app key.

The biometric design is worth stating plainly: the device holds the private
key and signs a server-issued nonce; the server stores only the public key.
A full database compromise cannot forge a biometric login.

### A03 — Injection

- **SQL.** Eloquent parameterises everything. Where raw SQL is unavoidable —
  the spatial distance calculation — bindings are used:
  ```php
  ->selectRaw('ST_Distance_Sphere(location, ST_SRID(POINT(?, ?), 4326)) AS distance_m', [$lng, $lat])
  ```
  User-supplied filter and sort keys never reach the builder unless
  allow-listed in `BaseRepository::$filterable` / `$sortable`.
- **Search terms.** `%` and `_` are escaped before a `LIKE`, so a user cannot
  turn a search into a full scan.
- **XSS.** The API returns JSON only and sets
  `Content-Security-Policy: default-src 'none'`. React escapes by default;
  `dangerouslySetInnerHTML` appears nowhere in the codebase.
- **Command injection.** No user input reaches a shell. The OCR pipeline
  passes image *bytes* to Tesseract through pytesseract's Python API, never a
  filename on a command line.

### A04 — Insecure design

Business rules that are themselves security controls:

- **Geofence.** A price report must be filed within 500 m of the station. An
  attacker cannot sit at home poisoning stations across the country.
- **Band check.** A price deviating more than 15% from the city median is
  rejected before moderation. This is the control that stops a "₱5.00 diesel"
  attack.
- **Source precedence.** `PriceService::supersedes()` refuses to let a
  crowd report overwrite a fresh operator feed. A compromised user account
  cannot displace authoritative data.
- **Corroboration.** Two independent reporters agreeing publishes without a
  moderator; one reporter alone does not.
- **Trust scoring.** Auto-approval requires a history of accepted reports,
  with Wilson-style shrinkage so a 2-for-2 account does not outrank a
  90-for-100 one.

### A05 — Security misconfiguration

- `APP_DEBUG=false` in production; stack traces appear in responses only when
  debug is on, and never in production images.
- `expose_php=Off`, `server_tokens off` — no version disclosure.
- Security headers are set twice, at Nginx and in `SecurityHeaders`
  middleware, so a proxy misconfiguration does not strip them.
- Database and Redis ports are not published in `docker-compose.prod.yml`.
- Containers run as non-root (`www-data`, `nextjs`, `fip`).
- The AI service refuses to start in production without `SERVICE_TOKEN` set.

### A06 — Vulnerable components

Weekly `security.yml` workflow runs `composer audit`, `npm audit`,
`pip-audit`, gitleaks over the full history, and Trivy against the built
images with results uploaded as SARIF.

### A07 — Authentication failures

| Control | Implementation |
|---------|----------------|
| Password strength | 12 characters, mixed case, numbers, symbols, checked against Have I Been Pwned |
| Brute force | 5 failures → 15-minute lockout, recorded on the user row |
| Enumeration | identical response and comparable timing for an unknown address and a wrong password |
| MFA | TOTP (RFC 6238), single-use recovery codes |
| Session | 60-minute access token, blacklisted on logout until natural expiry |
| Audit | every attempt recorded in `login_attempts` with IP and reason |

The timing defence is explicit — an unknown address still burns a bcrypt
verification:

```php
if ($user === null) {
    Hash::check($password, '$2y$12$invalid…');   // comparable timing
    LoginAttempt::record($email, null, false, 'unknown_email');
    throw $this->invalidCredentials();
}
```

*Verified by* `tests/Feature/AuthenticationTest.php` — including an assertion
that the two failure messages are byte-identical.

### A08 — Software and data integrity

- `composer.lock` and `package-lock.json` are committed; CI uses `npm ci`.
- Docker images are pinned to minor versions.
- The audit log is immutable at the model level:
  ```php
  static::updating(static fn () => throw new LogicException('Audit log entries are immutable.'));
  static::deleting(static fn () => throw new LogicException('Audit log entries cannot be deleted.'));
  ```

### A09 — Logging and monitoring failures

| Signal | Where |
|--------|-------|
| Every create/update/delete on an audited model | `audit_logs` |
| Every authentication attempt | `login_attempts` |
| Request latency and status | `api_request_logs` |
| Security events | `storage/logs/security.log`, 90 days |
| Audit events | `storage/logs/audit.log`, 365 days |
| Slow queries (>500 ms) | application log |

Secrets are redacted before an audit row is written — `Auditable::auditableAttributes()`
strips `password`, `remember_token`, `mfa_secret`, `mfa_recovery_codes` and
`biometric_key`.

Read traffic is sampled at 5% to keep telemetry from dwarfing business data;
writes and errors are logged in full.

### A10 — Server-side request forgery

The only outbound requests to a user-influenced host are OAuth redirects, and
those go through Socialite's fixed provider endpoints. The DOE scraper targets
a configured URL, not a user-supplied one. The AI service is reachable only on
the internal Docker network; Nginx restricts `/internal/ai/` to RFC 1918
ranges.

---

## 7.3 Rate limiting

Two independent layers. Nginx stops floods before they reach PHP; Laravel
applies per-user fairness.

| Endpoint group | Nginx | Laravel |
|----------------|-------|---------|
| `/auth/*` | 1 r/s, burst 5 | 10/min |
| `/ocr/scan` | 10 r/min, burst 3 | 10/min |
| AI endpoints | 30 r/s, burst 10 | 20/min |
| Other API | 30 r/s, burst 40 | 120/min |

Laravel keys by user id when authenticated and by IP otherwise, so a shared
office NAT does not exhaust one budget for everybody behind it.

---

## 7.4 Data protection

### Personal data held

| Category | Purpose | Retention |
|----------|---------|-----------|
| Name, email, phone | account identity | account lifetime + 90 days |
| Home city | default map centre, alert radius | account lifetime |
| Vehicle registration, plate, VIN | vehicle management | account lifetime |
| Fill-up records with coordinates | expense tracking, fraud detection | 5 years (financial records) |
| Report geotags | anti-fraud verification | 90 days, then coarsened |
| Device identifiers, FCM tokens | push delivery | until the device is removed |

### Subject rights

| Right | Mechanism |
|-------|-----------|
| Access | `GET /auth/me`, plus a full export via a report |
| Rectification | `PUT /profile` |
| Erasure | `DELETE /profile` — soft delete, purged after 90 days |
| Portability | JSON export through the reports module |
| Objection | notification preferences, per category |

Erasure is a soft delete first because financial records have a statutory
retention period that outlives an account. Purge anonymises rather than
deleting the fill-up rows, so aggregate analytics remain correct.

### Location handling

Location is the most sensitive category here, so it is deliberately
constrained:

- Requested at point of use, never in the background.
- Report geotags are stored as a *distance from the station*, not as a track.
- Declining location still yields a usable product — the map falls back to
  Metro Manila with a visible notice, rather than nagging.
- No location data is shared with third parties.

---

## 7.5 Backups and recovery

| Aspect | Policy |
|--------|--------|
| Frequency | nightly full dump, 02:00 Asia/Manila |
| Verification | `gzip -t` before older backups are pruned |
| Retention | 30 days local, 90 days off-site |
| Off-site | S3, `STANDARD_IA` |
| Encryption | server-side at rest; TLS in transit |
| RPO | 24 hours |
| RTO | 2 hours |
| Restore test | quarterly, into a scratch environment |

`backup.sh` refuses to prune if the new dump is empty or fails its integrity
check. A corrupt backup discovered during a restore is worse than no backup,
because it destroys the assumption you were relying on.

---

## 7.6 Incident response

| Severity | Definition | Response | Notification |
|----------|-----------|----------|--------------|
| P1 | data breach or full outage | immediate | NPC within 72 h; users within 72 h |
| P2 | privilege escalation, price data poisoning | < 1 hour | affected users within 24 h |
| P3 | degraded service | < 4 hours | status page |
| P4 | minor defect | next release | release notes |

Containment steps for a suspected credential compromise:

```bash
# 1. Invalidate every session
docker compose exec api php artisan jwt:invalidate-all

# 2. Force a password reset for the affected cohort
docker compose exec api php artisan users:force-reset --role=system_admin

# 3. Review what happened
docker compose exec api php artisan tinker
>>> AuditLog::where('created_at', '>', now()->subHours(24))
...     ->whereIn('event', ['updated', 'deleted'])->get();
```

---

## 7.7 Verification status

Being precise about what has actually been exercised:

| Control | Status |
|---------|--------|
| Tenant isolation | ✅ 6 automated tests |
| Authentication and lockout | ✅ 9 automated tests |
| Price integrity gates | ✅ 7 automated tests |
| Expense validation | ✅ 8 automated tests |
| Source precedence | ✅ 6 automated tests |
| Fraud detection rules | ✅ 5 automated tests |
| Dependency and container scanning | ✅ weekly in CI |
| Rate limiting | ⚠️ configured, not load-tested |
| Backup restore | ⚠️ scripted, needs a scheduled drill |
| Penetration test | ❌ not yet commissioned |

The last three are honest gaps, not oversights. Rate limits should be verified
under load before launch; the restore drill needs a scratch environment; and a
third-party penetration test is a pre-launch requirement, not something the
implementing team can self-certify.

---

## 7.8 Pre-launch checklist

- [ ] Every `CHANGE_ME` in `.env` replaced
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `php artisan key:generate` and `jwt:secret` run
- [ ] TLS certificates installed; HSTS preload submitted
- [ ] Database and Redis ports unpublished
- [ ] MFA enforced for every administrative account
- [ ] Backup cron installed and one restore verified
- [ ] Rate limits load-tested
- [ ] Penetration test completed and findings closed
- [ ] Incident contacts and the NPC notification path documented
