# 11. AI Services

Five capabilities behind one internal FastAPI service. Each has a working
fallback, because a system that fails hard when a model artefact is missing is
not production-ready.

---

## 11.1 Design principle

**Never fabricate.** Every model in this service either produces a grounded
answer or says it cannot.

That principle shows up concretely:

- Fewer than 14 days of price history returns `model_used:
  "insufficient-data"` and confidence 0.25, not a plausible-looking number.
- Fewer than 20 transactions returns no fraud scores at all — "anomalies" in a
  tiny sample are just the tails of the distribution.
- No OpenAI key means the advisor answers from the context deterministically
  and reports `grounded: false`, so the client can label it.
- The OCR pipeline reports what it saw with per-line confidence; it does not
  decide whether a price is correct.

---

## 11.2 Price forecasting

### The problem

Philippine pump prices adjust weekly, following a formula that is essentially a
pass-through of the previous week's import cost. The inputs are known; the
question is how much of each week's movement transmits, and when.

### Approach

Three models, blended by confidence.

**Gradient boosting (XGBoost)** models the *change* directly from regressor
deltas. This is the primary model when a trained artefact exists, because the
relationship it captures is exactly the pass-through mechanism.

**Prophet** models the *level* series and captures trend and seasonality. It is
indifferent to the exogenous shocks that actually drive weekly adjustments, but
it anchors the forecast when regressors are noisy.

**Pass-through fallback** — always available, fully transparent:

```
Δpump ≈ 0.46·Δmops_php + 0.28·Δcrude_php + 0.18·fx_effect + 0.08·momentum
```

Every term is converted to pesos per litre before weighting, so the driver
panel shows real numbers rather than opaque coefficients.

### Unit conversion

Crude and refined products are quoted in USD per barrel; pump prices in pesos
per litre. One barrel is 158.987 litres:

```python
delta_php_per_litre = ((mops_now - mops_prev) / 158.987) * usd_php_rate
```

The FX effect is applied separately at roughly 55% import content of a ₱58/L
pump price — the peso affects the imported portion, not the taxes and margins
layered on top.

### Blending

```python
weights = confidences / confidences.sum()
blended = np.dot(values, weights)

# Disagreement between models is itself evidence of uncertainty.
dispersion = np.std(values)
confidence = np.dot(weights, confidences) - min(dispersion / 1.5, 0.35)
```

The dispersion penalty is the part that matters. Three models agreeing on
+₱0.50 is a much stronger signal than three models spread across −₱0.20 to
+₱1.20 that happen to average +₱0.50.

### Output shaping

- **Rounded to 5 centavos.** PH boards move in 5-centavo steps, so a forecast
  of ₱0.5237 is false precision.
- **Interval widens as confidence falls** — `spread = max(0.10, (1 −
  confidence) × 1.2)`. An honest forecast states how unsure it is.
- **Multi-week horizon decays** at 0.55 per week. Weekly adjustments
  mean-revert; repeating the first-week estimate would compound an error
  rather than a signal.

### Accuracy tracking

`PriceForecastService::scoreAgainstActuals()` back-fills `actual_change` and
`absolute_error` once the DOE announces, and `/forecasts/accuracy` publishes
the trailing figures. Direction accuracy is the headline metric because that
is what users act on.

---

## 11.3 OCR

### The problem

Price boards are a hostile target: high-contrast LED or flip digits,
photographed at an angle, often at night, frequently with glare. Plain
Tesseract on such an image is a coin flip.

### Three-stage pipeline

**1. Preprocessing.** Five deliberately different variants are produced —
CLAHE-enhanced greyscale, Otsu threshold, adaptive threshold, **inverted**, and
upscaled. The inverted variant is not an afterthought: LED boards are
light-on-dark, and standard thresholding destroys them entirely.

**2. Multi-pass recognition.** Every variant runs through Tesseract with
`--psm 6` (a uniform text block, which is what a price board is), and the pass
with the best mean word confidence wins. This costs CPU and buys reliability.

**3. Structured parsing.** Tesseract's word-level output is reassembled into
physical lines using its block/paragraph/line numbers — layout matters, because
a price belongs to the label on its own row. Each line is matched against known
fuel labels and a price pattern.

### Fuel label matching

Boards use brand names, not the codes we store. The alias table maps
reality to canonical codes:

| Board text | Code |
|-----------|------|
| XTRA UNLEADED, Silver, Regular | `gasoline_ron91` |
| V-Power, XCS, Blaze 100, Gold | `gasoline_ron95` |
| XTRA ADVANCE, Blaze, Platinum | `gasoline_ron97` |
| Diesel, Gasoil, Turbo | `diesel` |
| Diesel Max, V-Power Diesel | `diesel_premium` |

### Validation

Prices outside ₱25–₱200 are dropped at parse time — that band excludes both a
misplaced decimal and the litre counter that often sits beside the price.

Where a line holds several numbers, the largest plausible value is taken: on a
real board the price is nearly always the largest figure on its row.

Local band validation happens in Laravel, not here, because that check needs
the station's city median — context the AI service does not have.

---

## 11.4 Conversational advisor

### Groundedness

The constraint is absolute: an advisor that invents a price sends someone
driving to a station that does not exist, or refuelling on a forecast that was
never made.

So the model receives a compact factual snapshot assembled by Laravel — the
user's vehicles, recent spend, live nearby prices, this week's forecast — and a
system prompt that is explicit about its limits:

> Answer ONLY from the CONTEXT block. If the context does not contain what is
> needed, say so plainly and suggest what the user could do in the app to get
> it. Never invent a price, a station name, a distance or a forecast.
>
> When you cite a forecast, state its confidence. A 55%-confidence call is not
> the same as an 85% one and the user deserves to know which they have.

Temperature is 0.3 — this is advice, not creative writing.

### Deterministic answers

Some questions should never touch a language model. "Should I refuel today?"
is arithmetic on the forecast and the user's tank capacity, so it is computed
in `FuelAdvisorService::shouldRefuelToday()` and returns the same answer every
time it is asked.

The same applies to "why did my consumption increase?", which compares two
30-day periods and attributes the change to distance, price or efficiency with
explicit percentages.

### Fallback

Without an API key the service answers from the snapshot using templates for
the four most common questions, and returns `grounded: false`. The client
labels it. Silently passing off a template as a model response would be a lie
of omission.

---

## 11.5 Fraud detection

Two tiers with different jobs.

### Tier 1 — rules, inline (Laravel)

Six deterministic checks on every fill-up. They are cheap, explainable, and
catch the common abuses. Signals combine with a noisy-OR:

```php
$score = 1.0;
foreach ($signals as $signal) {
    $score *= (1 - $signal['weight']);
}
$score = 1 - $score;
```

Several weak signals together clear the threshold that none would alone, but no
single rule can exceed 1.0 — which prevents one aggressive rule from
dominating.

### Tier 2 — isolation forest, nightly (Python)

Rules cannot see patterns. A driver who over-dispenses 3% every week produces
no individually suspicious transaction, but the pattern is obvious across 30
days.

Features are all *relative*, which is essential — absolute litres would simply
rank trucks above motorcycles:

| Feature | Definition |
|---------|-----------|
| `tank_fill_ratio` | litres ÷ tank capacity |
| `efficiency_ratio` | km/L ÷ the vehicle's own baseline |
| `cost_per_km` | total ÷ distance |
| `hours_since_previous` | gap between fills |
| `price_deviation` | price ÷ the batch median |
| `distance_per_day` | distance ÷ elapsed days |

`random_state=42` is fixed deliberately: a fleet manager disputing an alert
needs the same score to come back when it is re-run.

Scores are normalised between the 5th and 99th percentiles, so one extreme
outlier does not flatten every other score toward zero.

Each result names the features that pushed it out, and maps the leading one to
a human alert type — `overfill`, `ghost_refuel`, `rapid_refuel`. A bare score
tells a manager nothing actionable.

---

## 11.6 Predictive maintenance

Two clocks run simultaneously and the earlier one governs:

- **Calendar** — the fixed interval from the last service
- **Odometer** — projected from observed daily distance

```python
days_to_due = remaining_km / observed_daily_km
```

For a van covering 4,000 km a month, a 5,000 km oil change falls due in five
weeks — not the six months the calendar interval implies. The projection is
capped at 730 days, because beyond two years the usage pattern will have
changed and the precision would be fictional.

Confidence scales with evidence: `0.55 + min(fill_ups, 10) × 0.035`, so a
vehicle with two logged fill-ups produces a low-confidence estimate and the UI
can present it as such.

The rule-based date remains contractual. The prediction is advisory and drives
early reminders.

---

## 11.7 Route optimisation

Geometry comes from Google Directions where a key is configured, and from a
geometric model tuned for Philippine conditions where it is not:

| Parameter | Value | Basis |
|-----------|-------|-------|
| Urban circuity | 1.35 | road distance vs. straight line in Metro Manila |
| Highway circuity | 1.18 | expressway corridors |
| Urban speed | 22 km/h | achievable average, not the limit |
| Expressway speed | 80 km/h | |
| Toll rate | ₱2.60/km | NLEX/SLEX class 1 |

Pricing happens in Laravel, which holds the live price data. "Cheapest" is
total journey cost — fuel plus tolls plus the detour to reach a cheap station —
not the lowest pump price. A ₱2/L saving 8 km off-route is a false economy, and
the model charges the detour back so it cannot win on pump price alone.

---

## 11.8 Operating the service

### Health

```bash
curl -s http://localhost:8001/health | jq .capabilities
```

Reports which capabilities are actually available, so a silent downgrade to a
fallback path is visible rather than mysterious.

### Metrics

Prometheus at `/metrics`: `fip_ai_requests_total` by endpoint and status,
`fip_ai_request_duration_seconds` with buckets shaped to the real latency
profile — OCR and LLM calls are seconds, everything else milliseconds.

### Training

Model artefacts belong in `MODEL_DIR` as
`price_forecast_<version>.joblib`. The service loads on boot and falls back
cleanly when absent. Train against a read replica; never against the primary.

### Cost control

The language model is the only metered dependency. Three things keep spend
bounded:

- **Context trimming** — five vehicles and five nearby stations maximum.
- **History window** — the last ten conversational turns, not the whole
  transcript.
- **Deterministic routing** — the highest-volume question, "should I refuel
  today?", never reaches the model at all.

Token usage is recorded per session in `ai_chat_sessions.token_usage`.
