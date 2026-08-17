# Feature freeze — end of Phase 2.5

**Frozen 2026-08-17**, at `claude/fleet-telemetry-foundation`.

The feature set below is closed. What follows is hardening, verification and
whatever the pilot turns up — not new surface area. A freeze is only useful if
it is specific about what it covers, so this lists what is in, what was
deliberately left out, and what would justify reopening it.

## What is in

Everything here is built, tested, deployed to production and exercised in a
browser against it.

| Area | What it does |
|---|---|
| Fleet overview | Live availability, spend, utilisation, plan capacity |
| Vehicles | Register, edit, fuel readings, efficiency, documents |
| Drivers | Roster, add, edit, assignment to a vehicle |
| Assignments | Assign and release; releases date the row rather than delete it |
| Trips and dispatch | Draft → dispatched → in progress → completed, plus cancellation before the wheels turn; odometer capture feeding vehicle mileage |
| Driver mobile app | Sign in, see today's trip, start and close it, log a fill-up, report position and battery |
| Device health | Online state, battery, entitlement to report, last position and its age |
| Fleet map | Where every vehicle is now, with stale positions drawn as stale |
| Location history | One vehicle, one window, the recorded track with gaps left as gaps, and a replay |
| Fuel and expenses | Fill-ups, receipt scanning, spend and efficiency summaries |
| Fuel anomalies | Detection, severity, evidence, resolution |
| Maintenance | Schedules, due and overdue |
| Reports | Definitions, generation, download through the API |
| Subscription capacity | Vehicles, seats and devices enforced at creation; visible to the tenant |
| Tenancy and roles | Company isolation, seven roles, per-permission gating |
| Station and price intelligence | Directory, map, prices, forecasts, DOE reference data |
| Administration | Companies, users, roles, settings, audit, system health |

## What is deliberately out

Not missing. Decided against, for the reasons given.

- **Live GPS tracking / follow-a-vehicle.** The map answers "where is it now",
  refreshed on a timer. Continuous tracking is a different product and a much
  heavier privacy commitment.
- **Route optimisation, geofencing, ETA prediction.** No route engine, no
  boundaries, no arrival estimates.
- **Billing.** Subscription tiers describe capacity. There is no payment
  provider, no invoice and no payment state anywhere, and a tier is set by an
  administrator rather than earned by a transaction.
- **Any automated action from a fuel anomaly.** Detection and evidence only; a
  person decides.
- **Driver scoring or behaviour ranking.** The data would support it. Nothing
  in the brief asked for it, and building it quietly would be a significant
  thing to do to identifiable people.
- **Multi-currency and internationalisation.** Peso and English only.
- **Offline-first mobile.** Positions queue and flush; nothing else does.

## What is open, and why it is not a code problem

These are tracked as open items rather than gaps in the freeze.

| Item | Blocked on |
|---|---|
| Production mail | Points at MailHog by configuration; needs a real SMTP credential and DNS |
| Subscription tier numbers | A business decision. The placeholders are not a price list |
| Fuel economy figures | Time and data: odometers on fills, or enough trips to measure between |
| Location retention period | A privacy decision; the default errs towards deleting sooner |

## What would justify reopening the freeze

- A defect found by the pilot in something listed as in.
- A privacy or security finding.
- A decision on one of the open items above that requires code to honour it —
  for example real tier numbers that need a migration, or a retention period
  the pruner does not yet express.

Anything else waits for the next phase.
