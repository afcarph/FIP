# 10. Administrator Manual

For system administrators, station operators and fleet managers.

---

## 1. Roles

Eight roles, ordered by privilege. `level` is stored on the role so the UI can
sort and compare them.

| Role | Level | Scope |
|------|-------|-------|
| Super Administrator | 1 | everything; bypasses every gate |
| System Administrator | 2 | everything except minting other administrators |
| Gas Station Administrator | 3 | their own stations' prices and details |
| Fleet Manager | 4 | one company's vehicles, drivers and fuel data |
| Company Manager | 4 | one company, read-heavy, plus reporting |
| Driver | 6 | assigned vehicle; can log fill-ups |
| Registered User | 7 | own vehicles and expenses |
| Guest | 9 | public prices only |

Two rules are enforced in code, not convention:

- A super administrator bypasses every policy check
  (`Gate::before` in `AuthServiceProvider`). This is deliberate: a
  mis-scoped policy must never lock the platform owner out of their own system.
- Only a super administrator may grant `super_admin` or `system_admin`.
  `UserAdminController::assignableRoles()` silently strips those roles from any
  assignment made by anyone else, which closes the obvious escalation path.

### Adjusting permissions

**Admin → Roles** lists each role with its permission set. Permissions are
named `<group>.<verb>` — `prices.moderate`, `fleet.reports`, `ai.manage`.

The super administrator role cannot be edited. Removing a permission from it
would achieve nothing (the gate bypass ignores permissions) while creating a
misleading impression that it had been restricted.

---

## 2. Moderating crowd reports

**Admin → Moderation** holds everything awaiting a decision, sorted by reporter
trust score so the most likely-good submissions surface first.

### What has already been checked

By the time a report reaches you, it has passed:

- **Geofence** — filed within 500 m of the station
- **Price band** — within 15% of the city median for that fuel
- **Duplicate check** — not the same user, same station, within the hour

So the queue contains plausible reports from users who have not yet earned
auto-approval, not obvious rubbish.

### Deciding

Approving publishes the price through the same path as an operator update:
`station_prices` is updated, history is archived, caches are cleared and
standing alerts fire.

Rejecting requires a reason, which is recorded and shown to the reporter. This
is not bureaucracy — a reporter who learns *why* a submission was rejected
files better ones, and their trust score recovers.

### Trust scores

Auto-approval requires a trust score at or above 0.80. The score uses
Wilson-style shrinkage:

```
trust = (approved + 2) / (approved + rejected + 4)
```

The constants matter. A user with 2 approvals out of 2 scores 0.67, not 1.0 —
not yet enough. A user with 90 out of 100 scores 0.88. New users start at 0.55
if email-verified, 0.40 otherwise.

### OCR review

**Admin → Moderation → OCR** shows scans with the extracted lines, each with
its own confidence, and the original photograph. You can correct a price before
approving.

Watch for the misread that matters: a decimal in the wrong place. "58.90" read
as "5.890" is caught by the band check, but "58.90" read as "53.90" is
plausible and will not be. Compare against the photograph.

---

## 3. Station operators

**Stations → My stations → Update prices.** Enter the current board price for
each grade and save.

Operator prices are authoritative — they outrank crowd and OCR data and will
not be displaced by them while fresh. That is a responsibility: a stale
operator price blocks community corrections for 72 hours before it is
considered stale enough to override.

Update on the morning of any adjustment, not the evening before.

---

## 4. Fleet management

### The dashboard

**Fleet** shows utilisation, spend, maintenance and anomalies. Utilisation is
the metric worth acting on first: the share of active vehicles that recorded
any distance this month. An idle vehicle still costs insurance, registration
and depreciation.

### Assigning drivers

**Fleet → Drivers → Assign.** A vehicle and a driver each hold at most one open
assignment; assigning automatically releases any previous one on both sides.

Assignments are historical, not a simple pointer. When a fuel anomaly surfaces
three weeks later, the system can answer "who held this vehicle on 23 July?" —
which a `driver_id` column on the vehicle could not.

### Fraud alerts

Two tiers feed **Fleet → Alerts**.

**Tier 1** runs on every fill-up as it is recorded — six deterministic rules:

| Alert | Trigger |
|-------|---------|
| `overfill` | more litres than the tank holds, plus 5% tolerance |
| `ghost_refuel` | efficiency implausibly high — distance without matching fuel |
| `excess_consumption` | efficiency far below baseline |
| `rapid_refuel` | two fills within 30 minutes |
| `odometer_rollback` | reading below the previous one |
| `price_mismatch` | paid more than ₱2/L above the station's published price |
| `location_mismatch` | geotag over 2 km from the claimed station |

Signals combine with a noisy-OR, so several weak indicators together can clear
the threshold that none would alone.

**Tier 2** runs nightly — an isolation forest over each company's last 30 days,
catching patterns no single transaction reveals: consistent slight
over-dispensing, or distances that never quite match the fuel.

### Investigating an alert

1. Open the alert and read the evidence — it names the specific figures.
2. Check the transaction against the driver's route for that day.
3. Set the status: **Investigating**, **Confirmed** or **Dismissed**.
4. Record what you found. That note is the audit trail if it becomes a
   disciplinary matter.

A dismissed alert is not wasted. Legitimate explanations — a jerry can, a
replaced odometer — are exactly what tunes the thresholds in
`config/fip.php`.

---

## 5. AI model management

**Admin → AI** shows the registry and the service's live capabilities.

### Reading the health panel

```json
"capabilities": {
  "prophet": true,
  "trained_booster": false,
  "openai": true,
  "ocr": true
}
```

`trained_booster: false` means the gradient-boosted model artefact is not
loaded and forecasting is running on its transparent fallback. That is degraded
but honest — the fallback publishes its own weights in the driver panel.

### Promoting a model version

Compare metrics before activating. For forecasting, **direction accuracy**
matters more than mean absolute error: users act on "increase or rollback", and
being right about direction while off by ₱0.10 beats being close in magnitude
while wrong about the sign.

Activating a version retires the previous one automatically. Past forecasts
keep pointing at the model that produced them, so the accuracy record stays
attributable.

### Running a forecast out of band

**Admin → AI → Run forecast** regenerates immediately. Use it after a market
shock, or when the scheduled Monday run failed.

**Score forecasts** back-fills actual adjustments and recomputes accuracy.
Normally automatic on Wednesdays.

---

## 6. Users

**Admin → Users** for creation, role assignment and suspension.

Suspension is preferred to deletion. A suspended account keeps its history —
its fill-ups still count toward aggregate statistics, its price reports remain
attributable — while access is revoked immediately.

### Investigating an account

```bash
# Sign-in history
docker compose exec api php artisan tinker
>>> App\Domain\User\Models\LoginAttempt::where('email', 'user@example.com')
...     ->latest('attempted_at')->limit(20)->get();

# Everything the account changed
>>> App\Domain\User\Models\AuditLog::where('user_id', 42)
...     ->latest()->limit(50)->get(['event', 'auditable_type', 'created_at']);
```

The audit log cannot be edited or deleted, by anyone, including you. That is
what makes it evidence.

---

## 7. Reports

**Reports** offers five definitions, each gated by permission:

| Report | Scope | Permission |
|--------|-------|-----------|
| Fuel Expense Summary | user | `reports.view` |
| Fleet Utilisation | fleet | `fleet.reports` |
| Regional Price Movement | platform | `reports.platform` |
| Station Performance | station | `station.reports` |
| Fraud Alert Register | company | `fraud.view` |

Formats: PDF, Excel, CSV, JSON. Reports under 5,000 rows generate inline;
larger ones queue and notify you when ready.

Download links are signed and expire after 15 minutes. Files are removed after
30 days — regenerate rather than archiving links.

---

## 8. Routine operations

### Daily

```bash
curl -fsS https://api.fip.ph/api/health
docker compose exec api php artisan queue:failed
```

Check the moderation queue depth. A backlog means either a spam wave or that
auto-approval thresholds are set too conservatively.

### Weekly

Confirm the Monday forecast ran and the Tuesday DOE import applied. Review the
API metrics panel for endpoints whose latency has drifted. Check disk usage.

### Monthly

Review fraud alert outcomes — a high dismissal rate means thresholds need
loosening. Verify a backup restores. Review administrator accounts and remove
anyone who has left.

### Tuning without a deploy

`config/fip.php` holds the business thresholds, and the `settings` table
overrides them at runtime:

| Setting | Default | Raise it when |
|---------|---------|---------------|
| `crowd_auto_approve_trust` | 0.80 | bad reports are getting through |
| `max_price_deviation_pct` | 0.15 | genuine reports are being rejected |
| `fraud_score_threshold` | 0.65 | too many false positives |
| `min_forecast_confidence` | 0.60 | low-confidence forecasts are misleading users |

Change one at a time and observe for a week. Changing several at once makes it
impossible to tell which one helped.

---

## 9. Emergencies

### Bad prices published widely

```bash
# Find what a compromised account touched
docker compose exec api php artisan tinker
>>> App\Domain\Pricing\Models\StationPrice::where('reported_by', $userId)->get();

# Suspend the account
>>> App\Domain\User\Models\User::find($userId)->update(['status' => 'suspended']);
```

Then re-import the DOE advisory to restore authoritative prices, and clear the
price caches.

### The AI service is down

Nothing else breaks. Stored forecasts still display; OCR and chat return 503
with `ai_service_unavailable`. Check `docker compose logs ai-service` — the
usual cause is memory pressure during a Prophet fit.

### Suspected credential compromise

```bash
docker compose exec api php artisan jwt:invalidate-all
```

Every session ends immediately. Then force a password reset for the affected
cohort and review the audit log for the preceding 24 hours.

---

## 10. Escalation

| Severity | Definition | Response |
|----------|-----------|----------|
| P1 | data breach or full outage | immediate; NPC within 72 h |
| P2 | privilege escalation or price poisoning | within 1 hour |
| P3 | degraded service | within 4 hours |
| P4 | minor defect | next release |

Every P1 or P2 gets a written post-mortem: what happened, what the impact was,
why it was possible, and what changed so it cannot recur. Blameless — the
purpose is to fix the system, not to find someone to fault.
