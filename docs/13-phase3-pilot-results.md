# 13. Phase 3 Real-Device Pilot — Results

**Status: INCOMPLETE. The field portion has not been run.**

Server-side tests (10, 11, 12, 15) are complete and recorded below with real
measurements. Tests 1–9, 13 and 14 require a person holding the device: walking
or driving for 30–60 minutes, reading the battery, toggling connectivity, and
watching the indicator change. Those are marked **NOT TESTED** and must not be
filled in from anything but an actual run.

Everything reported here is measured. Nothing is estimated, and no telemetry
was created for this document — the records described are the 8 genuine
positions the pilot device produced on its own.

Plan: [13-phase3-pilot-plan.md](13-phase3-pilot-plan.md).

---

## Pilot identity

| | |
|---|---|
| Account | `driver@fip.ph` (user 6, Jomar Dela Cruz) |
| Vehicle | **NS 1001** (vehicle 3), Mitsubishi L300 |
| Device | **8** — iOS, uuid `hl3cywo69s…`, not revoked |
| Assignment | 1 active, vehicle 3 → driver 1, unreleased |
| Device app/OS version | **Not recorded** — `app_version` and `os_version` are both null on device 8 |
| App build | Not recorded by the device at registration |
| Environment | Local development API, MySQL, `Asia/Manila` |
| Baseline row count | **8** `device_locations` before the field pilot |

⚠️ Device 8 registered without `app_version` or `os_version`. The API accepts
both and the setup flow sends neither, so the record cannot say which build
produced the data. That is a defect in its own right — see *Defects*.

## Configuration in force during the observed window

| Setting | Value |
|---|---|
| Sampling interval | 120 s |
| Minimum distance | 50 m |
| Maximum accuracy accepted | 1000 m |
| Maximum batch size | 200 |
| Retention | 30 days, status **approved** |

---

## Results

| # | Test | Result |
|---|---|---|
| 1 | Login as driver | **NOT TESTED** — requires the device |
| 2 | My Vehicle shows NS 1001 | **NOT TESTED** on device. Data path verified server-side: the driver's `/vehicles` response carries `assigned_driver.user_id = 6` on NS 1001 only |
| 3 | Tracking indicator visible | **NOT TESTED** — requires the device |
| 4 | Enable foreground tracking | **NOT TESTED** |
| 5 | Permission requested only on enable | **NOT TESTED** on device. Verified structurally: `Geolocator.requestPermission()` is reachable only via `TrackingService.start()` ← `TrackingController.enable()` ← the driver's own switch or the setup button. No launch path touches it |
| 6 | 30–60 minutes of movement | **NOT TESTED** |
| 7 | Battery / GPS / frequency record | **PARTIAL** — GPS accuracy and update frequency **OBSERVED** from real data below. Battery **NOT TESTED** |
| 8 | Offline queue and recovery | **OBSERVED, not deliberately induced** — see below |
| 9 | Close/minimise → foreground-only | **NOT TESTED** |
| 10 | Driver cannot reach other vehicles | **PASS** |
| 11 | Backend receives genuine positions | **PASS** |
| 12 | Timestamps and coordinates correct | **PASS** |
| 13 | Indicator states during the test | **NOT TESTED** |
| 14 | Errors / rejected uploads | **PASS** for the observed window — 0 rejections |
| 15 | Retention / pruning configured | **PASS** |

---

## Test 11 & 12 — the genuine positions (PASS)

8 rows, device 8 → vehicle 3, spanning **139 minutes** on 2026-08-09.

| recorded_at (Asia/Manila) | latitude, longitude | accuracy (m) |
|---|---|---|
| 13:07:09 | 14.7958015, 121.0166619 | 6.91 |
| 13:16:52 | 14.7958015, 121.0166619 | 6.91 |
| 13:22:30 | 14.7957673, 121.0166128 | 5.80 |
| 14:00:47 | 14.7957717, 121.0166061 | 6.30 |
| 14:15:32 | 14.7957757, 121.0166140 | 6.73 |
| 14:46:08 | 14.7957758, 121.0166139 | 5.18 |
| 15:18:21 | 14.7957742, 121.0166190 | 4.68 |
| 15:26:27 | 14.7957746, 121.0166176 | 5.50 |

**Coordinates** — all within roughly 40 m of one another, consistent with a
stationary device. Plausible for Metro Manila; no null island, no drift, none
rejected.

**Accuracy** — 4.68 m to 6.91 m, mean ≈ 5.9 m. Well inside the 1000 m limit and
better than the sampling design assumes.

**Timestamps** — `recorded_at` and `received_at` are on the same clock
(`Asia/Manila`), ordered, and never in the future. The timezone fix holds under
real device data.

## Test 8 — offline queue (OBSERVED)

Not deliberately induced, but the data shows the behaviour clearly:

| received_at | positions |
|---|---|
| 15:18:21 | **7** |
| 15:26:27 | 1 |

Seven positions recorded between 13:07 and 15:18 were uploaded in a **single
batch** at 15:18:21 — the oldest had waited 7,872 s (2h 11m). The eighth
uploaded immediately.

This is real evidence that the local queue holds positions and flushes them
together, that a long gap does not lose data, and that `recorded_at` survives
the delay intact rather than being stamped at upload. It does **not** replace
test 8: nobody deliberately dropped the network, so the *cause* of the delay is
unknown — it may have been connectivity, or the app not being foregrounded.
A deliberate airplane-mode test is still required.

## Test 7 — frequency (OBSERVED, partial)

Intervals between consecutive positions: 9m 43s, 5m 38s, **38m 17s**, 14m 45s,
30m 36s, 32m 13s, 8m 6s.

Against a configured 120 s interval, every gap is far longer. The likeliest
explanation is the 50 m minimum-distance filter suppressing samples from a
stationary device — which is the filter working as designed. It cannot be
confirmed without a moving test, and a moving test is exactly what would
distinguish "filter working" from "sampling stopping when it should not".

**Positions uploaded:** 8. **Rejections:** 0. **Battery:** not measured.
**Network conditions:** not recorded.

## Test 10 — driver isolation (PASS)

Live, as `driver@fip.ph`:

```
/vehicles/3/alerts          (own vehicle)        200
/vehicles/4/alerts          (NS 1002)            403
/vehicles/1/alerts          (other company)      403
/fleet/locations            (all positions)      403
/fleet/vehicles/3/locations (own history)        403
/fleet/vehicles/4/locations (other history)      403
/fleet/fraud-alerts         (company-wide)       403
```

The driver reaches exactly one thing: alerts for the vehicle they are assigned.
Note they cannot read even their **own** vehicle's location history — that
needs `devices.location.history`, which drivers do not hold. Deliberate.

## Test 15 — retention (PASS)

Retention **30 days**, status **approved**. Cutoff 2026-07-10; **0** pilot rows
older than it. `model:prune --pretend` reports *"No prunable records found"* —
the pruner is wired, discovers the model, and correctly leaves recent telemetry
alone.

---

## Privacy observations

- Positions carry 7-decimal precision — roughly centimetre resolution. Matches
  what the PIA documents; not coarsened.
- All 8 rows are stamped to vehicle 3 at write time, so a future reassignment
  cannot retroactively reattribute them.
- The 2h 11m upload delay means a position can sit on the device long after it
  was taken. The PIA describes what the server retains, not what the handset
  holds; worth a line if queue lifetime can be long.
- No location data left the tenant: every cross-vehicle and cross-company read
  was refused.

## Driver usability observations

**NOT TESTED.** Requires someone using the app.

---

## Defects found

**1. Device registration records no app or OS version.** `app_version` and
`os_version` are null on device 8. The API accepts both; the mobile setup flow
sent neither. **FIXED — see below.**

**2. Observed sampling interval does not match configuration.** Gaps up to
38 minutes against a 120 s interval. **INVESTIGATED, not changed — see below.**

---

## Fix 1 — build attribution (applied)

`TrackingService.register()` already accepted `app_version` and `os_version`;
the setup flow simply never passed them. It now resolves both before
registering:

| Field | Source | Column |
|---|---|---|
| `app_version` | `PackageInfo` → `version+buildNumber`, e.g. `1.0.0+1` | VARCHAR(24) |
| `os_version` | `Platform.operatingSystemVersion`, e.g. `Version 26.5.2 (Build 23F84)` | VARCHAR(32) |

No new database fields and no API contract change — the fields, validation and
columns already existed.

Both values are best-effort: a platform-channel failure yields null and
registration proceeds. A driver must never be blocked from setting up their
phone because a version string could not be read, and null is more honest than
a placeholder that would look like real attribution.

Both are truncated to the column limits (24 / 32). The request validates
`max:24` and `max:32`, so an over-long version would otherwise fail validation
and take the whole registration with it. **This was caught during
implementation** — the first attempt truncated at 64 and would have produced a
422 on a long OS string.

Registration is idempotent on `(user_id, device_uuid)`, so **device 8 will pick
up its version the next time setup runs** — no reset, no re-registration, and
the existing 8 telemetry rows are unaffected.

Covered by `test/build_identity_test.dart` (5 tests): both values produced, the
build number included when present and omitted when not, and both within the
column limits.

## Investigation 2 — the long gaps are not a tracking failure

**Conclusion: the 50 m distance filter does *not* explain the gaps, and
tracking did not stop.**

Distances between consecutive positions, computed from the stored coordinates:

| From → to | Gap | Movement |
|---|---|---|
| 13:07:09 → 13:16:52 | 9.7 min | **0.00 m** |
| 13:16:52 → 13:22:30 | 5.6 min | 6.51 m |
| 13:22:30 → 14:00:47 | 38.3 min | 0.87 m |
| 14:00:47 → 14:15:32 | 14.8 min | 0.96 m |
| 14:15:32 → 14:46:08 | 30.6 min | 0.02 m |
| 14:46:08 → 15:18:21 | 32.2 min | 0.58 m |
| 15:18:21 → 15:26:27 | 8.1 min | 0.16 m |

Maximum consecutive movement **6.51 m**; maximum displacement from the first
point **6.85 m**. The device did not move.

`TrackingService._sample` compares each fix against `_lastSampled`, and
`_lastSampled` is updated **only when a sample passes the filter**. So under one
continuous service instance a stationary device queues exactly **one** row and
then nothing.

Eight rows exist. Every one is under 7 m from its predecessor, so every one
would have been suppressed — unless `_lastSampled` was null at the time, which
is true only for the **first sample of a fresh `TrackingService`**. `start()`
samples immediately before arming the timer.

So the eight positions are eight *first samples*: the app being opened eight
times, not the timer firing every 120 s. The irregular gaps (5.6 to 38.3 min)
match a person opening an app, not a scheduler. The filter worked exactly as
designed — it suppressed the periodic samples in between.

**Nothing was changed.** The interval stays 120 s and the filter stays 50 m.
This explains the observation without a code change, and a moving test is still
required to confirm periodic sampling works when the vehicle is actually in
motion.

Covered by `test/sampling_distance_filter_test.dart` (4 tests), which replays
the eight real coordinates through `Geolocator.distanceBetween` — the same pure
calculation `_sample` uses. No GPS movement is fabricated; the one synthetic
coordinate is a control proving the threshold does not reject everything.

No tracking, privacy or retention behaviour was changed. The 8 genuine records
are untouched.

## Recommendations

1. **Run the field portion.** Tests 1–9, 13 and 14 are the pilot; nothing here
   substitutes for them. Use the plan's record sheets.
2. ~~Record the build.~~ **Done.** Re-run device setup once on the pilot phone
   so device 8 picks up its version before the field run.
3. **Induce the offline case deliberately.** Airplane mode for 10 minutes while
   moving, then restore, and confirm the queue flushes without duplicates.
4. **Test while moving.** The stationary analysis rules out a tracking failure,
   but only movement confirms that periodic sampling produces rows when the
   vehicle is actually in motion.
5. **Measure battery over a representative shift**, not a short walk.
6. **Confirm foreground-only by observation** — background the app, wait past
   an interval, and check no rows arrive.
