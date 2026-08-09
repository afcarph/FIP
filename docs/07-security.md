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
| Personal location history | movement patterns of identifiable people | foreground-only collection, tenant scoping, a separate permission for history, scheduled pruning, no third-party sharing |
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
| Vehicle location history | fleet vehicle monitoring | configurable — **pending approval**, see Location handling |

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
constrained. There are two distinct kinds of location in FIP and conflating
them would misdescribe both.

**Point-of-use location (all users).** The map and the nearby-station search
ask for a position at the moment they need one and keep nothing. Report geotags
are stored as a *distance from the station*, not as a track. Declining still
yields a usable product — the map falls back to Metro Manila with a visible
notice, rather than nagging.

**Fleet vehicle tracking (registered driver devices only).** A device that has
been registered to a vehicle reports its position periodically **while the FIP
mobile application is open and in use**, so that a fleet operator can see where
their vehicles are and associate fuel events with a place.

| Question | Answer |
|----------|--------|
| Why is it collected? | To show a fleet operator the current and recent location of their own vehicles, and to give fuel and anomaly records an operating context |
| Which devices? | Only devices explicitly registered through the app and associated with a vehicle. An unregistered device reports nothing |
| Whose data? | The vehicle, the registered device, and the driver assigned to that vehicle at the time |
| Continuous or periodic? | **Periodic.** Sampled on an interval, not streamed. The interval is configuration (`location.sampling_interval_seconds`), not a fixed product promise |
| While the app is active? | **Yes** — and only then |
| Background tracking? | **No.** FIP does not request background location. iOS declares `NSLocationWhenInUseUsageDescription` only, with no `UIBackgroundModes`; Android requests only foreground location. When the app is backgrounded or closed, collection stops |
| If permission is denied? | Tracking is skipped. The app keeps working: everything except vehicle tracking behaves exactly as before, and the app does not re-prompt on a loop |
| Can it be disabled? | Yes — revoke the OS permission, or revoke the device registration server-side, which stops ingestion for that device immediately |
| If GPS is unavailable? | Nothing is recorded. No position is invented, and no last-known value is resubmitted as if it were current |

**What is stored.** Latitude, longitude, accuracy, and where the platform
supplies them altitude, speed and heading; the device's own timestamp
(`recorded_at`) and the server's receipt time (`received_at`); and the device
and vehicle it belongs to. The vehicle is stamped at write time, so reassigning
a device later does not rewrite where it has been.

**Timestamps.** Both `recorded_at` and `received_at` are stored in the
application timezone, `Asia/Manila` — not UTC. This matches every other
timestamp in the schema. A device reporting UTC is converted on the way in, so
that the two columns of a single row are always on one clock: when they were
not, a replayed position compared as newer than the current one and moved a
vehicle backwards on the map. Clients reading the API should treat returned
times as `Asia/Manila` unless an offset says otherwise.

**Access.** Location is tenant-scoped like every other fleet record — an
operator sees only their own company's vehicles. Two separate permissions
apply, because they answer different questions: `devices.location.view` for
where a vehicle *is now*, and `devices.location.history` for where it *has
been*. The second is deliberately not implied by the first.

**Auditability.** Device registration, vehicle association and revocation are
written to the immutable audit log. Location rows themselves are not audited
individually — the volume would drown the log — but every read path that
exposes history is permission-gated.

**Retention.** Location history is pruned on a schedule. The period is set by
an administrator under *Admin → Privacy & data retention*, held in the
`settings` table, and gated on `settings.manage` — a permission only
`super_admin` and `system_admin` hold. A fleet manager who can see where a
vehicle has been cannot decide how long that record survives.

The environment variable `FIP_LOCATION_RETENTION_DAYS` remains as an
installation fallback for a system nobody has configured yet, but it cannot
override an administrator's value; when the two disagree the setting reports
`requires_review` so somebody reconciles them. An unset period prunes nothing
rather than everything: a missing value is not an instruction to erase history.

The setting reports its own provenance, which is the point of holding it in the
database at all:

| Status | Meaning |
|---|---|
| `approved` | An administrator set this deliberately |
| `provisional` | Nobody has set it; the platform is running on the fallback |
| `requires_review` | Configured and fallback disagree; the configured value wins |

⚠️ **Until the status reads `approved`, the period is provisional and requires
business and privacy approval before this feature is operated on real drivers.**
The nearest approved precedents in this document are 90 days for report geotags
and 30 days for generated reports, but neither is a movement track of an
identifiable person, and the correct period for one is a decision for the
business rather than for this implementation.

Every change is audited with its previous value, the administrator, the time and
the request context.

No location data is shared with third parties.

The full assessment — lawful basis, subject rights, and the open retention
decision — is in [12-privacy-impact-assessment.md](12-privacy-impact-assessment.md).

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
