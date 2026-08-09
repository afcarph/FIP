# 13. Phase 3 Real-Device Pilot — Plan and Record Sheets

**Status: NOT YET RUN.** This document is the instrument, not the outcome. Every
result field is blank on purpose. Nothing here may be filled in from a
simulator, from a unit test, or from expectation — a pilot that reports numbers
nobody measured is worse than no pilot, because it retires a risk that is still
live.

Two tests below are marked **already executed**, because they are server-side
and were run against a controlled environment. Everything requiring a handset,
a driver or a vehicle is outstanding.

---

## 13.1 Blocking precondition

⚠️ **Do not run this pilot on real drivers while the retention period reports
`provisional`.**

Check *Admin → Privacy & data retention* first. The status must read
**Approved**, meaning an administrator set the period deliberately after
business and privacy sign-off. See
[12-privacy-impact-assessment.md](12-privacy-impact-assessment.md) §12.5.

A pilot is real collection from real people. Running one under a placeholder
period means collecting movement data with no answer to "how long are you
keeping this?" — the one question a participant is most entitled to ask.

## 13.2 Scale

| | |
|---|---|
| Devices | 2–5 |
| Vehicles | 1–3 |
| Drivers | 1–2 |
| Accounts | Pilot/test users and vehicles wherever possible |

Small enough that any single anomaly can be chased to its cause. A ten-device
pilot that produces one unexplained gap is a worse instrument than a
three-device pilot that produces none.

## 13.3 Device register

Record per device. **No personal data beyond the driver reference already held
in the platform** — no phone numbers, no personal email, no IMEI.

| Field | Device 1 | Device 2 | Device 3 |
|---|---|---|---|
| Device ID (server) | | | |
| Platform | | | |
| OS version | | | |
| App version | | | |
| Vehicle | | | |
| Driver (user ref) | | | |
| Registration status | | | |

## 13.4 Server readiness checklist

Run immediately before the pilot, against the pilot environment.

| Check | Method | Result |
|---|---|---|
| API reachable | `GET /api/health` → 200 | |
| JWT auth works | Sign in, token accepted | |
| Device registration | `POST /devices` | |
| Vehicle association | `PATCH /devices/{id}` | |
| Location ingestion | `POST /devices/location` | |
| Latest location | `GET /fleet/locations` | |
| History | `GET /fleet/vehicles/{id}/locations` | |
| Rate limiting | Exceed `FIP_RL_LOCATION`, expect 429 | |
| Pruning enabled | Scheduler running `model:prune` | |
| Retention configured | Status reads **Approved** | |
| Audit logging | `setting.updated` / `device.*` entries present | |

## 13.5 Privacy checklist

| Item | Confirmed |
|---|---|
| Privacy policy available to participants | |
| PIA available | |
| Collection behaviour explained in plain language | |
| Foreground-only behaviour understood | |
| Participants can tell when tracking is active | |
| Retention period deliberately configured and stated to participants | |
| Participants know what is collected and who can see it | |

---

## 13.6 Tests

Each test states what it proves. Fill in observations, not verdicts, while
running; convert to PASS/FAIL afterwards.

### Test 1 — Device registration
Install, authenticate, confirm `X-Device-Id`, register, associate to vehicle,
confirm the device appears in admin, confirm audit entries.
> Expected: registered · correct vehicle · audit entry created.

### Test 2 — Location permission
**iOS:** When-In-Use requested; Always *not* requested; no background
permission; privacy manifest present in the installed build; purpose string
comprehensible.
**Android:** fine/coarse foreground requested; no background permission; no
foreground-service permission.
**Both**, exercise all four paths: Allow · Deny · Previously denied · GPS
disabled. Each must degrade gracefully rather than crash or hang.

### Test 3 — Location sampling
Drive a controlled route with the app foregrounded.

| Field | Value |
|---|---|
| Sampling interval (configured) | |
| Locations received | |
| Start / end time | |
| Distance travelled | |
| Typical GPS accuracy | |

> Interval is approximate by design. GPS availability and OS scheduling vary;
> do not fail this test on timing jitter alone.

### Test 4 — Location accuracy
Sample at a parked location, on open road, in an urban area, and in a weak-GPS
area if practical. Record expected vs reported position, accuracy and
timestamp. Confirm obviously invalid fixes are rejected — the server already
refuses (0, 0), future timestamps and fixes worse than
`FIP_LOCATION_MAX_ACCURACY_M`.

### Test 5 — Offline and retry
Disable connectivity mid-route. Confirm the queue grows, nothing crashes, and
growth stays bounded (cap is 500 entries, oldest discarded). Restore
connectivity: confirm upload, no duplicates, correct `recorded_at`, correct
vehicle.

### Test 6 — Duplicate / idempotency
Replay an already-uploaded batch. Record row counts before and after.
> Expected: no new rows. The unique key on `(device_id, recorded_at)` makes a
> re-flush a no-op.

### Test 7 — Device revocation
Revoke from admin, then attempt a location submission and normal device calls.
> Expected: submission rejected · tracking stops · **history retained** ·
> revocation audited.

### Test 8 — Device reassignment
Device A → Vehicle 1, collect. Device A → Vehicle 2, collect.
> Expected: earlier rows still point at Vehicle 1. History is never rewritten.

### Test 9 — Battery impact
| Field | Value |
|---|---|
| Device / OS | |
| Starting battery % | |
| Duration | |
| Ending battery % | |
| Approximate drain | |
| Screen-on time | |
| Tracking duration | |

> Set no acceptable threshold before measuring. Measure first, then decide
> whether the number is tolerable. A target chosen in advance tends to become
> the answer.

### Test 10 — Mobile data usage
| Field | Value |
|---|---|
| Device | |
| Tracking duration | |
| Locations sent | |
| Approximate data used | |
| Average per day | |

### Test 11 — Server data volume
Measure and compare against the bench figures below, which were taken on a
development database with synthetic rows and are **not** a production
prediction:

| Bench measurement | Value |
|---|---|
| Row size including indexes | ~205 bytes |
| Per vehicle at 30 days | ~1.5 MB |
| Index share of table size | larger than the data itself |

Record actual: locations/device/day · locations/vehicle/day · average row size ·
index growth · database growth.

### Test 12 — Tracking transparency
Confirm the driver can tell that tracking is active, why, and what happens if
permission is denied.

> ⚠️ **Known gap, expected to fail.** The app has no persistent tracking
> indicator today. This is recorded in the PIA as a residual risk: a driver who
> leaves the app open off-shift keeps reporting with nothing on screen saying
> so. Document the finding here; do not fix it mid-pilot, because changing the
> app under test invalidates tests 3, 9 and 10.

### Test 13 — Latest location
While driving, confirm the API returns the correct vehicle, position and latest
timestamp, and that an older position cannot displace a newer one. The
server-side guard is covered by regression tests; this confirms it end to end
with real clock skew.

### Test 14 — Location history
After the route: records exist, ordering is chronological, timestamps correct
(**Asia/Manila**, not UTC), vehicle association correct, and unauthorised users
are refused.

### Test 15 — Retention and pruning ✅ **already executed**
Run in a non-production environment with controlled data and a temporary short
retention. Executed on the development database:

- Retention set to **2 days** through the admin setting.
- Six rows seeded at 0.5, 1, 1.5, 3, 5 and 9 days old.
- `model:prune --path=app/Domain --pretend` → reported 3 records, deleted none;
  row count unchanged at 6.
- `model:prune --path=app/Domain` → deleted exactly 3.
- Survivors: the 0.5, 1 and 1.5 day rows. Zero rows older than the period
  remained.
- Vehicles and devices untouched (5 and 1, before and after).
- Environment restored: probe data removed, setting deleted, status back to
  `provisional`.

> Repeat in the pilot environment before the pilot ends, at the approved
> period, to confirm the scheduler runs it unattended rather than by hand.

---

## 13.7 Acceptance criteria

**Functionality** — registration · association · collection · ingestion ·
offline queue · retry · latest location · history · revocation · reassignment
preserving history.

**Security** — unauthorised access refused · device identity not spoofable via
body fields · revoked devices refused · vehicle scope enforced · history
permission enforced · lifecycle changes audited.

**Privacy** — foreground-only confirmed on real devices · no background
permission requested · privacy manifest ships · purpose clear · retention
explicitly configured · documentation matches observed behaviour.

**Reliability** — no crashes · offline recovery · duplicates handled · invalid
locations rejected · battery measured · data usage measured.

## 13.8 Result report

Copy this out and fill it in. Leave anything unmeasured blank rather than
guessing.

```text
FIP PHASE 3 REAL-DEVICE PILOT

Pilot period:
Build/version:
Environment:

Devices:
Vehicles:
Drivers:

Device registration:   PASS / FAIL
Permissions:           PASS / FAIL
Location sampling:     PASS / FAIL
Accuracy:              PASS / FAIL
Offline queue:         PASS / FAIL
Retry/idempotency:     PASS / FAIL
Revocation:            PASS / FAIL
Reassignment:          PASS / FAIL
Battery:               PASS / FAIL
Mobile data:           PASS / FAIL
Server volume:         PASS / FAIL
Latest location:       PASS / FAIL
Location history:      PASS / FAIL
Retention/pruning:     PASS / FAIL
Security:              PASS / FAIL
Privacy:               PASS / FAIL

Average locations/device/day:
Average data/device/day:
Observed battery impact:
Observed server growth:

Issues found:
Severity:
Recommended fixes:

Overall: PASS / PASS WITH CONDITIONS / FAIL
```

## 13.9 What happens next

Phase 4 (fleet map and dashboard) does not start until this pilot has run and
its issues are resolved. The order matters: the map's design depends on how
much data actually arrives and how fresh it is, and both are unknown until
real devices report from real vehicles.

```text
Admin retention setting  ✅ built
   ↓
Business/privacy approval  ← outstanding
   ↓
Real-device pilot  ← outstanding
   ↓
Battery + data volume results  ← outstanding
   ↓
Pilot issues resolved
   ↓
Phase 3 production approval
   ↓
Phase 4 fleet map / dashboard
```
