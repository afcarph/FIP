# 12. Privacy Impact Assessment — Vehicle Location Tracking

**Scope:** collection, storage and access of vehicle location from registered
driver devices (Phase 3).
**Status:** implementation complete; **retention period not yet approved**.
**Related:** [07-security.md](07-security.md) §7.4, "Location handling".

This assessment covers the one feature in FIP that records where an
identifiable person has been. It is deliberately short: a document nobody
finishes reading protects nobody.

---

## 12.1 What is collected, and why

| | |
|---|---|
| **Purpose** | Show a fleet operator where their own vehicles are, and give fuel and anomaly records an operating context |
| **Lawful basis** | Employment/contractual — an operator monitoring vehicles they own, driven by people they engage. **To be confirmed by the business**, along with the driver-facing notice |
| **Data subjects** | Drivers carrying a registered device in a company vehicle |
| **Categories** | Coordinates, accuracy, and where the platform supplies them altitude, speed and heading; device clock (`recorded_at`) and server receipt time (`received_at`), both stored in the application timezone `Asia/Manila` rather than UTC, consistent with the rest of the schema; device and vehicle identifiers |
| **Special category data** | None collected. Note that a movement track can *imply* sensitive facts — a clinic visit, a place of worship, a union office — even though FIP never records them as such. This is the main reason retention is bounded |
| **Volume** | One sample per interval (default 120s) per active device, only while the app is open, skipping samples where the vehicle has not moved 50 m |

## 12.2 What is not collected

- **No background location.** iOS declares `NSLocationWhenInUseUsageDescription`
  and nothing else; there is no `UIBackgroundModes`. Android declares only
  foreground permissions, not `ACCESS_BACKGROUND_LOCATION`. The mobile client
  runs no background isolate. Collection stops when the app does.
- **No tracking of unregistered devices.** An ordinary user's phone reports
  nothing; registration against a vehicle is a separate, authorised step.
- **No location from the web application.** The browser map asks for a position
  at point of use and keeps none.
- **No third-party sharing**, and no advertising or profiling use.

## 12.3 Risks and mitigations

| Risk | Mitigation | Residual |
|---|---|---|
| Covert tracking outside working hours | Foreground-only by construction, not by policy — the permissions and the absence of a background worker are what enforce it | Low. A driver who leaves the app open off-shift is still reporting; the app should surface an obvious indicator |
| A manager replaying a driver's movements | `devices.location.history` is a separate grant, not implied by seeing the fleet map. `company_manager` holds current-position access only | Medium — depends on who the business grants history to |
| Cross-tenant exposure | Tenant scoping at query level (`forUser`), plus `VehiclePolicy` on the vehicle | Low |
| Device impersonation | The device is resolved from the authenticated session *and* `X-Device-Id`; the header alone proves nothing | Low |
| A lost handset continuing to report | Any of the owner, a platform administrator, or a manager of the vehicle's company may revoke it; revocation is immediate and terminal | Low |
| Indefinite accumulation | Scheduled pruning via `Prunable` | **Open — period not yet approved** |
| Re-identification via a stale association | The vehicle is stamped into each row at write time, so reassigning a device does not retroactively attribute old journeys to a new vehicle | Low |

## 12.4 Subject rights

| Right | Mechanism |
|---|---|
| Access | Location history for a vehicle via the fleet API, permission-gated; a driver's own device list via `GET /devices` |
| Objection / withdrawal | Revoke the OS permission, or revoke the device registration. Either stops collection immediately |
| Erasure | Device revocation stops collection but **retains history**, because the record of where a company vehicle went is the operator's operational record. Erasure of the history itself is a business decision and has no self-service route today |
| Transparency | [07-security.md](07-security.md) §7.4 and the in-app permission rationale |

## 12.5 Retention — the open decision

⚠️ **This is the outstanding item, and the feature should not be operated on
real drivers until it is closed.**

No approved retention period exists for a movement track. The fallback is
**30 days** (`fip.location.retention_days`), chosen only because it is the
shortest retention already present in the platform, so the default errs towards
deleting sooner rather than keeping longer. It is a placeholder, not a
recommendation, and the platform now says so out loud rather than leaving it
to be inferred from a config comment.

The nearest approved precedents are neither equivalent:

| Existing policy | Period | Why it is not a precedent |
|---|---|---|
| Report geotags | 90 days, then coarsened | A single point at a station, not a track |
| Generated reports | 30 days | Derived artefacts, not personal data |
| Fill-up records with coordinates | 5 years | Retained for statutory financial reasons |

**Required before launch:**

1. Business and privacy owners agree a period, and an administrator sets it
   under *Admin → Privacy & data retention*. The change is audited, so the
   decision has an owner and a date rather than living in a deployment file.
2. The personal-data table in [07-security.md](07-security.md) is updated with
   the agreed figure.
3. The driver-facing notice and the lawful basis in §12.1 are confirmed.

Until step 1 happens the setting reports itself as **provisional**, and the
admin screen says so in as many words. That is deliberate: a period that is
merely *in effect* should not be mistakable for one that was *chosen*, even
when the two are the same number of days.

An unset period prunes nothing rather than everything — an absent value must
never be read as "delete it all" — so an unconfigured environment accumulates
rather than destroys. That is the safer failure, but it is still a failure, and
it is why the decision cannot be deferred indefinitely. The admin form will not
accept `0` for the same reason: switching off retention entirely is not a
decision that should be one keystroke away.

## 12.6 Verification

Controls in this document are covered by
`tests/Feature/DeviceRegistrationTest.php`,
`tests/Feature/DeviceLocationTest.php` and
`tests/Feature/LocationHistoryTest.php` — including the permission split
between current position and history, cross-tenant refusal, device
impersonation, revocation, and that a retention period of zero prunes nothing.
