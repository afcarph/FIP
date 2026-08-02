# 8. Testing Strategy

---

## 8.1 What we test, and why those things

Test effort follows consequence, not code coverage. Four areas carry
disproportionate risk:

| Area | If it breaks | Coverage |
|------|--------------|----------|
| Tenant isolation | one company sees another's fuel costs | 6 feature tests |
| Price integrity | poisoned data sends drivers to the wrong station | 13 tests across service and feature layers |
| Expense arithmetic | every downstream figure is wrong, silently | 8 unit tests |
| Authentication | account takeover | 9 feature tests |

Everything else is tested proportionately. A test that only asserts a getter
returns what the setter set has negative value — it locks in an implementation
without protecting anything.

---

## 8.2 The pyramid, as actually built

```
        ╱╲          E2E — critical journeys only
       ╱  ╲         (planned: Playwright)
      ╱────╲
     ╱      ╲       Feature — HTTP in, database out
    ╱  ~55%  ╲      Laravel: 4 classes, 28 tests
   ╱──────────╲
  ╱            ╲    Unit — pure logic, no I/O
 ╱     ~45%     ╲   Laravel 3 · Python 3 · TS 1 · Dart 2
╱────────────────╲
```

The middle layer is deliberately the heaviest. In a system whose complexity
lives in the interaction between authorisation, validation and persistence, a
test that exercises the real route through the real database catches classes of
bug that unit tests structurally cannot.

---

## 8.3 Backend

`backend/tests/` — PHPUnit 11, SQLite in memory, `RefreshDatabase`.

### Unit

| Class | Covers |
|-------|--------|
| `PriceServiceTest` | source precedence, history archiving, staleness, implausible prices |
| `FuelExpenseServiceTest` | km/L between full tanks, partial fills, receipt rounding, odometer rollback |
| `FraudDetectionServiceTest` | overfill, ghost refuel, rollback, clean transactions, noisy-OR escalation |

The precedence tests are the ones that matter most:

```php
public function test_a_crowd_report_does_not_overwrite_a_fresh_operator_price(): void
{
    $this->service->recordPrice($this->station, $this->fuelType->id, 57.50, 'operator');

    $result = $this->service->recordPrice($this->station, $this->fuelType->id, 55.00, 'crowd', 0.9);

    $this->assertSame(57.50, (float) $result->price);
}

public function test_a_crowd_report_replaces_a_stale_operator_price(): void
{
    $staleAt = now()->subHours((int) config('fip.pricing.stale_after_hours') + 6);

    $this->service->recordPrice($this->station, $this->fuelType->id, 57.50, 'operator', 1.0, null, $staleAt);

    $result = $this->service->recordPrice($this->station, $this->fuelType->id, 55.00, 'crowd', 0.9);

    $this->assertSame(55.00, (float) $result->price);
}
```

Together they pin both halves of the rule. Testing only the first would allow a
regression where crowd data never gets in at all.

### Feature

| Class | Covers |
|-------|--------|
| `AuthenticationTest` | registration, password strength, lockout, MFA challenge, enumeration resistance |
| `VehicleAuthorizationTest` | own vehicles, cross-tenant denial, driver rights, admin override |
| `CrowdReportTest` | geofence, duplicates, required fields, self-voting, moderation |
| `PriceIntelligenceTest` | public access, radius accuracy, operator permissions, comparison maths |

The enumeration test asserts something easy to regress:

```php
public function test_an_unknown_email_and_a_wrong_password_are_indistinguishable(): void
{
    $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', ...]);
    $wrong   = $this->postJson('/api/v1/auth/login', ['email' => 'known@example.com', ...]);

    $this->assertSame($unknown->json('error.message'), $wrong->json('error.message'));
}
```

A well-meaning developer improving the error message for one case would break
this immediately, which is exactly what should happen.

```bash
make test-backend
docker compose exec api php artisan test --filter=PriceServiceTest
docker compose exec api php artisan test --coverage --min=70
```

---

## 8.4 AI service

`ai-service/tests/` — pytest. **20 tests, 13 verified green locally** (the API
tests require FastAPI's test client, which needs the full dependency set).

| Module | Covers |
|--------|--------|
| `test_forecast_service.py` | direction from regressors, 5-centavo rounding, interval widening, horizon decay, driver ranking, sparse-history confidence |
| `test_fraud_service.py` | small-sample refusal, clean population, overfill isolation, score ordering, reproducibility |
| `test_api.py` | health capabilities, metrics, forecast round trip, upload rejection, grounded flag |

Two properties are asserted that are easy to lose:

```python
def test_change_is_rounded_to_five_centavos(service):
    # PH boards quote in 5-centavo steps; the forecast must match.
    remainder = round(abs(response.change_amount) % 0.05, 4)
    assert remainder < 1e-6 or abs(remainder - 0.05) < 1e-6


def test_scoring_is_reproducible(service):
    # A fixed random_state means an investigation can be reproduced later.
    assert [(r.id, r.anomaly_score) for r in first.results] == \
           [(r.id, r.anomaly_score) for r in second.results]
```

The second matters operationally: a fleet manager disputing an alert needs the
same score to come back when it is re-run.

```bash
make test-ai
docker compose exec ai-service pytest -q --cov=app
```

---

## 8.5 Frontend

`frontend/src/__tests__/` — Vitest with jsdom. **9 tests, all passing.**

Coverage focuses on the formatting layer, because it is used on every screen
and a change there is invisible until a number looks wrong in production:

```ts
it('classifies movement with a dead band around zero', () => {
  expect(priceTrend(0.5)).toBe('up');
  expect(priceTrend(-0.5)).toBe('down');
  // Sub-centavo noise should not read as a movement.
  expect(priceTrend(0.0001)).toBe('flat');
});

it('measures Makati to Quezon City at roughly 14 km', () => {
  const km = distanceKm(14.5547, 121.0244, 14.676, 121.0437);
  expect(km).toBeGreaterThan(13);
  expect(km).toBeLessThan(15);
});
```

Type checking and the production build are themselves part of the suite:

```bash
npm run typecheck   # tsc --noEmit — currently clean
npm run test        # 9 passing
npm run build       # compiles 11 routes with no warnings
```

---

## 8.6 Mobile

`mobile/test/` — 17 tests over formatters and error parsing.

The error-parsing tests pin a distinction that affects what the user is told:

```dart
test('distinguishes a connection failure from a server error', () {
  final offline = ApiException.fromDio(
    DioException(requestOptions: options, type: DioExceptionType.connectionError),
  );

  // Telling a user on a weak signal that the server broke sends them
  // hunting for the wrong problem.
  expect(offline.isOffline, isTrue);
});
```

```bash
cd mobile && flutter test
flutter analyze --no-pub
```

---

## 8.7 Continuous integration

`.github/workflows/ci.yml` runs four suites in parallel, then builds images.

| Job | Steps |
|-----|-------|
| backend | Pint, PHPStan level 6, PHPUnit with coverage |
| ai-service | Ruff, mypy, pytest with coverage |
| frontend | tsc, ESLint, Vitest, production build |
| mobile | dart format, flutter analyze, flutter test |
| docker | build all three production images with layer caching |

A pull request cannot merge with any job red. The security workflow runs
weekly and on pull requests to `main`.

---

## 8.8 Gaps

Stated plainly rather than papered over:

| Gap | Impact | Plan |
|-----|--------|------|
| No end-to-end tests | a regression spanning frontend and API could ship | Playwright over four journeys: sign-in, find cheap fuel, log a fill-up, scan a board |
| No load testing | rate limits and connection pools are unverified under pressure | k6 against the price endpoints before launch |
| Widget tests are thin | mobile UI regressions rely on manual checking | golden tests for the forecast card and stat tile |
| No mutation testing | coverage may overstate real protection | Infection on the pricing and expense services |
| No visual regression | theme changes could break dark mode silently | Chromatic or Percy on the component library |

The first two are launch blockers. The rest are quality investments that can
follow.

---

## 8.9 Writing new tests

Three rules that keep the suite worth running:

1. **Name the behaviour, not the method.**
   `test_a_crowd_report_does_not_overwrite_a_fresh_operator_price` tells a
   reader what the system guarantees. `test_record_price_2` does not.

2. **One reason to fail per test.** When a test breaks, its name should
   already tell you what regressed.

3. **Assert the consequence, not the implementation.** Check that the stored
   price is 57.50, not that `supersedes()` returned false. The second locks in
   a private method and blocks refactoring for no safety gain.
