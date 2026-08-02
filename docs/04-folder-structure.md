# 4. Folder Structure

```
fip/
├── README.md
├── Makefile                       Self-documenting task runner
├── .env.example                   Every service's configuration in one file
│
├── database/                      Canonical data definitions
│   ├── schema.sql                 MySQL 8 DDL — 45 tables, views, partitions
│   ├── seed.sql                   Demo data as raw SQL
│   └── erd.mmd                    Mermaid entity relationship diagram
│
├── backend/                       Laravel 12 API
│   ├── app/
│   │   ├── Domain/<Context>/      One folder per bounded context
│   │   │   ├── Models/            Eloquent entities and row-level invariants
│   │   │   ├── Repositories/      All query construction
│   │   │   └── Services/          Business rules and transactions
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/
│   │   │   ├── Requests/          Validation, including cross-field rules
│   │   │   ├── Resources/         Response shaping
│   │   │   └── Middleware/
│   │   ├── Jobs/                  Queued work
│   │   ├── Policies/              Authorisation
│   │   ├── Console/Commands/      Artisan commands
│   │   ├── Providers/
│   │   ├── Services/External/     AI service and FCM clients
│   │   └── Support/               Base repository, concerns, exceptions
│   ├── config/                    Framework config + fip.php (domain tuning)
│   ├── database/{migrations,seeders,factories}/
│   ├── routes/{api,console}.php
│   ├── resources/views/reports/   PDF templates
│   └── tests/{Unit,Feature}/
│
├── ai-service/                    Python 3.11 / FastAPI
│   ├── app/
│   │   ├── api/v1/                Route handlers
│   │   ├── core/                  Config, logging, service-token auth
│   │   ├── schemas/               Pydantic request/response contracts
│   │   └── services/              Forecasting, OCR, advisor, fraud, routing
│   ├── models/                    Trained artefacts (gitignored)
│   └── tests/
│
├── frontend/                      Next.js 15 (App Router)
│   └── src/
│       ├── app/
│       │   ├── (auth)/            Unauthenticated routes
│       │   ├── (app)/             Authenticated shell + pages
│       │   └── globals.css        Design tokens for both themes
│       ├── components/
│       │   ├── ui/                Primitives (button, card, badge…)
│       │   ├── charts/            Recharts wrappers
│       │   ├── dashboard/         Stat tiles, forecast card, tables
│       │   ├── map/               Google Maps integration
│       │   └── layout/            App shell and navigation
│       ├── hooks/                 Auth, geolocation, TanStack Query hooks
│       ├── lib/                   API client, formatting utilities
│       └── types/                 API response types
│
├── mobile/                        Flutter 3
│   ├── lib/
│   │   ├── core/{config,network,theme,utils}/
│   │   ├── features/<feature>/presentation/
│   │   ├── shared/{providers,widgets}/
│   │   ├── main.dart
│   │   └── router.dart
│   └── test/
│
├── infra/
│   ├── docker-compose.yml         Development stack
│   ├── docker-compose.prod.yml    Production overlay
│   ├── docker/                    Multi-stage Dockerfiles
│   ├── nginx/                     Edge configuration
│   └── scripts/                   deploy.sh, backup.sh
│
├── docs/                          This documentation set
└── .github/workflows/             CI and security pipelines
```

---

## Why the backend is organised by domain rather than by type

The conventional Laravel layout groups by technical type: every model in
`app/Models`, every service in `app/Services`. That works until the model count
passes about thirty, at which point `app/Models` is a wall of forty files with
no indication of what relates to what.

Grouping by bounded context means a change to pricing touches
`app/Domain/Pricing/` and largely nothing else. The boundaries follow real
seams in the business:

| Context | Owns |
|---------|------|
| `User` | identity, companies, authentication, MFA, audit |
| `Station` | the directory, geography, brands, amenities |
| `Pricing` | live prices, history, advisories, crowd reports, OCR |
| `Vehicle` | the registry, odometer, documents |
| `Fleet` | fleets, drivers, assignments |
| `Expense` | fill-ups, trips, route plans |
| `Maintenance` | schedules, records, predictions |
| `Ai` | model registry, forecasts, chat, fraud alerts |
| `Notification` | notifications, templates, price alerts |
| `Reporting` | report definitions, runs, dashboards |

Cross-context references go through models, not through reaching into another
context's repositories. `Pricing` may reference a `Vehicle` model; it does not
use `VehicleRepository`.

---

## Route groups in the frontend

Next.js route groups — the parenthesised folders — do not appear in URLs. They
exist to give two sets of pages different layouts:

- `(auth)/` renders a centred card on a gradient. No navigation, because a
  signed-out user has nowhere to navigate to.
- `(app)/` renders the application shell with sidebar and header, and applies
  the client-side authentication guard.

`/login` and `/dashboard` are both top-level URLs; the grouping only decides
which layout wraps them.

---

## Where configuration lives

| File | Contains | Who edits it |
|------|----------|--------------|
| `.env` | secrets, hostnames, keys | operations |
| `backend/config/fip.php` | business rules — trust thresholds, fraud tolerances, rate limits | engineering, tunable per environment via env |
| `settings` table | runtime overrides on top of `fip.php` | administrators, through the console |

The layering matters: an administrator can raise the crowd auto-approval
threshold during a spam wave without a deploy, but the *default* is in version
control where it is reviewable.
