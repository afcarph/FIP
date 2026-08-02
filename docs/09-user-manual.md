# 9. User Manual

For motorists, riders and drivers. Fleet managers and administrators should
also read [the administrator manual](10-administrator-manual.md).

---

## Demo accounts

On a seeded installation, every account uses the password `Password123!`.

| Role | Email | What you can see |
|------|-------|------------------|
| Registered user | `user@fip.ph` | personal dashboard, two vehicles, fill-up history |
| Driver | `driver@fip.ph` | assigned vehicle, fill-up logging |
| Fleet manager | `fleet@fip.ph` | fleet dashboard, drivers, fraud alerts |
| Company manager | `manager@fip.ph` | company-wide vehicles and reports |
| Station operator | `station@fip.ph` | price board management |
| System administrator | `sysadmin@fip.ph` | moderation, users, AI models |
| Super administrator | `superadmin@fip.ph` | everything |

Change these before any real deployment. They are published here, which means
they are public.

---

## 1. Getting started

### Creating an account

Passwords need at least 12 characters with mixed case, a number and a symbol,
and are checked against a database of passwords exposed in known breaches. If
yours is rejected for that reason it is not a judgement on your creativity —
it means that exact password has appeared in a public dump and is already in
attackers' word lists.

You can also sign in with Google or Apple. If you later set a password on the
same email address, both routes reach the same account.

### Two-factor authentication

Under **Settings → Security**, choose **Enable two-factor authentication**,
scan the QR code with any authenticator app, and enter the six-digit code.

You are then shown eight recovery codes. **Save them somewhere other than your
phone.** They are shown exactly once, each works once, and they are the only
way back into your account if you lose the device.

### Biometric sign-in (mobile)

After signing in once on the app, **Settings → Biometric sign-in** enrols Face
ID or fingerprint. Your biometric never leaves the device: the app generates a
key pair, keeps the private half in the secure enclave, and sends only the
public half. The server can verify a signature but could never reproduce one.

---

## 2. Finding cheaper fuel

### The map

Open **Map**. Stations appear as price labels rather than generic pins, coloured
against the others currently in view:

- **Green** — cheapest third of what you can see
- **Amber** — middle third
- **Red** — dearest third

The ranking is relative to your view, not to a national threshold, so it stays
meaningful wherever you are.

Filter by fuel type, adjust the radius, and use the list beneath the map to
compare — comparing four prices in a column is far easier than tapping four
pins.

### If you decline location access

The map centres on Makati and says so. Everything still works; the results are
just not near you. You can enable location later without restarting.

### What the labels mean

| Label | Meaning |
|-------|---------|
| **Community** | reported by another user, not yet operator-confirmed |
| **Stale** | not updated in over 72 hours; treat as indicative |
| **24h** | open around the clock |
| **EV** | has electric vehicle charging |

---

## 3. Weekly price forecasts

Philippine pump prices adjust every Tuesday at 06:00, following an announcement
the previous evening. FIP predicts that adjustment on Monday morning.

Each forecast shows:

- **Direction and size** — "₱0.55/L increase expected"
- **Confidence** — as a bar, because a 55% call and an 85% call warrant
  different behaviour
- **Why** — tap **Why this forecast?** to see the ranked factors: regional
  product prices, crude oil, the peso exchange rate and recent momentum

### Reading confidence honestly

| Confidence | What it means for you |
|-----------|------------------------|
| **High** (85%+) | strong agreement across signals; act on it |
| **Moderate** (65–85%) | likely, but do not go far out of your way |
| **Low** (< 65%) | genuinely uncertain; refuel when convenient |

We publish our track record under **Forecasts → Track record**: every past
prediction against what the DOE actually announced. A forecast service that
never shows its errors is asking you to trust an unfalsifiable claim.

### "Should I refuel today?"

The AI Advisor answers this arithmetically rather than conversationally — the
same question always gets the same answer:

> **Refuel before Tuesday.** A ₱0.55/L increase is expected (81% confidence).
> On your 50 L tank that is about ₱28 saved by filling now.

---

## 4. Tracking what you spend

### Logging a fill-up

**Expenses → Log a fill-up**, then enter litres, price per litre and your
odometer reading. The total is calculated as you type.

The odometer is optional but it is what makes everything else work. Without it
we can total your spending; with it we can tell you your kilometres per litre
and your cost per kilometre, and spot when your consumption changes.

### Getting accurate efficiency figures

Fuel economy is only measurable between two **full** tanks. Fill to the first
click of the pump, note the odometer, drive normally, then fill to the first
click again. The distance divided by the litres it took to refill is your real
consumption.

Partial fills are still worth logging for spend tracking — they simply cannot
produce a km/L figure, and the app will not invent one.

### Understanding the numbers

| Figure | Meaning |
|--------|---------|
| **km/L** | distance per litre — higher is better |
| **Cost per km** | what each kilometre costs you in fuel |
| **Missed savings** | what you would have saved always using the cheapest station in each city |

"Missed savings" is deliberately unflattering. It is not a target — driving
20 km to save ₱1/L costs more than it saves — but it does tell you whether
your habitual station is quietly expensive.

---

## 5. Scanning a price board

Open the app, tap **Scan**, and photograph the board.

For a good read:

- **Avoid glare.** Stand slightly off-axis so the board is not reflecting sun
  or headlights.
- **Fill the frame.** Get close enough that the digits are large and sharp.
- **Be at the station.** Scans are geotagged so other drivers can trust them.

The app shows each price it read with its own confidence, and lists anything it
skipped with a reason. Check the figures before submitting — a misread decimal
point would mislead everyone nearby, which is why there is a review step rather
than automatic publication.

Your scans build a trust score. Once your submissions have a reliable history,
they publish immediately rather than waiting for review.

---

## 6. Reporting from the road

Beyond prices you can report:

- **Fuel shortage** — a grade is unavailable
- **Station closed** — temporarily or permanently
- **Long queue** — worth warning others about
- **Wrong information** — the listing does not match reality

Price reports must be filed within 500 metres of the station, and a price more
than 15% away from the local average is rejected. Both rules exist because a
few bad entries would make every price on the map untrustworthy.

Two people independently reporting the same price publishes it without waiting
for a moderator.

---

## 7. Your vehicles

Add each vehicle under **Vehicles**. Beyond the basics, two fields do real
work:

- **Tank capacity** — enables range estimates and catches impossible fill-ups
- **Rated efficiency** — the manufacturer's km/L figure, used as the baseline
  your actual consumption is compared against

### Maintenance reminders

Every vehicle gets a service schedule based on standard intervals — oil at
5,000 km or six months, tyre rotation at 10,000 km, and so on. Log each service
as you have it done and the schedule rolls forward.

Reminders use two clocks, calendar and odometer, and whichever comes first
wins. If you drive 4,000 km a month, your oil change is due in five weeks
regardless of what the six-month interval says. The app learns your actual
usage and adjusts.

Registration and insurance renewals are tracked too, with reminders at 60, 30,
14, 7 and 1 days.

---

## 8. Alerts

Under **Settings → Price alerts**, set a rule such as "tell me when diesel
drops below ₱57.50 within 5 km".

You will get at most one notification per rule every six hours, so a price
oscillating around your threshold cannot spam you.

**Quiet hours** suppress everything except urgent notifications — an overdue
registration will still reach you at 3 a.m., because that one carries a fine.

---

## 9. Questions people actually ask

**How current are the prices?**
Operator feeds are near-live. Community reports appear within minutes of
approval. Anything over 72 hours old is labelled "stale" rather than quietly
presented as current.

**Why is the price different when I get there?**
Stations can change prices at any time, and a Tuesday adjustment takes effect
at 06:00. The label shows when the price was last confirmed.

**How accurate are the forecasts?**
See **Forecasts → Track record** for the real numbers. We publish direction
accuracy and mean error over the trailing 26 weeks.

**Does the app track my location in the background?**
No. Location is requested only when you open the map or file a report. Report
geotags are stored as a distance from the station, not as a trail of where you
have been.

**Can I export my data?**
Yes — **Reports → Fuel Expense Summary**, as PDF, Excel or CSV.

**How do I delete my account?**
**Settings → Delete account**. Personal data is removed after a 90-day
recovery window; fill-up records are anonymised rather than deleted, because
they contribute to aggregate price statistics that other users rely on.
