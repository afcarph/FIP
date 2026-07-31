# Fuel Intelligence Platform (FIP)

> AI-powered fuel price intelligence, prediction, expense optimization and fleet
> management for the Philippine market.

FIP aggregates official DOE price advisories, crowd-sourced reports, OCR-scanned
price boards and global commodity signals into a single intelligence layer, then
exposes it through a web dashboard, a mobile app and a documented REST API.

---

## Table of contents

| # | Document |
|---|----------|
| 1 | [System Architecture](docs/01-system-architecture.md) |
| 2 | [Database Schema](docs/02-database-schema.md) |
| 3 | [ER Diagram](docs/03-er-diagram.md) |
| 4 | [Folder Structure](docs/04-folder-structure.md) |
| 5 | [API Documentation](docs/05-api-documentation.md) · [openapi.yaml](docs/openapi.yaml) |
| 6 | [Deployment Guide](docs/06-deployment-guide.md) |
| 7 | [Security Documentation](docs/07-security.md) |
| 8 | [Testing Strategy](docs/08-testing-strategy.md) |
| 9 | [User Manual](docs/09-user-manual.md) |
| 10 | [Administrator Manual](docs/10-administrator-manual.md) |
| 11 | [AI Services](docs/11-ai-services.md) |

---

## Repository layout

```
fip/
├── backend/       Laravel 12 API — domain, business rules, RBAC, persistence
├── ai-service/    Python / FastAPI — forecasting, OCR, chat, routing, fraud
├── frontend/      Next.js 15 + TypeScript + Tailwind + shadcn/ui web app
├── mobile/        Flutter client (iOS / Android)
├── database/      Canonical MySQL DDL, seed data, ERD source
├── infra/         Docker Compose, Dockerfiles, Nginx, deployment assets
└── docs/          Architecture, API, security, operations, manuals
```

See [docs/04-folder-structure.md](docs/04-folder-structure.md) for the annotated tree.

---

## Quick start (Docker)

```bash
git clone https://github.com/afcarph/pmo.git fip && cd fip
cp .env.example .env            # then edit secrets
make up                         # build + start the full stack
make bootstrap                  # migrate, seed, generate keys
```

| Service | URL |
|---------|-----|
| Web app | http://localhost:3000 |
| REST API | http://localhost:8000/api/v1 |
| Swagger UI | http://localhost:8000/api/documentation |
| AI service | http://localhost:8001/docs |
| MailHog | http://localhost:8025 |

Demo credentials are listed in [docs/09-user-manual.md](docs/09-user-manual.md#demo-accounts).

---

## Modules

| Module | Backend | AI | Web | Mobile |
|--------|:-------:|:--:|:---:|:------:|
| Authentication (JWT, MFA, OAuth, biometric) | ✅ | — | ✅ | ✅ |
| User profile & preferences | ✅ | — | ✅ | ✅ |
| Vehicle management | ✅ | — | ✅ | ✅ |
| Fuel price intelligence | ✅ | — | ✅ | ✅ |
| AI fuel price prediction | ✅ | ✅ | ✅ | ✅ |
| Fuel expense tracker | ✅ | — | ✅ | ✅ |
| AI fuel advisor (chat) | ✅ | ✅ | ✅ | ✅ |
| Gas station directory | ✅ | — | ✅ | ✅ |
| Interactive map | — | — | ✅ | ✅ |
| AI route optimization | ✅ | ✅ | ✅ | ✅ |
| OCR fuel scanner | ✅ | ✅ | ✅ | ✅ |
| Crowd-sourced prices + moderation | ✅ | ✅ | ✅ | ✅ |
| Fleet management | ✅ | ✅ | ✅ | ✅ |
| Maintenance & predictive servicing | ✅ | ✅ | ✅ | ✅ |
| Notification center (FCM) | ✅ | — | ✅ | ✅ |
| Reports & exports (PDF/Excel/CSV) | ✅ | — | ✅ | — |
| Admin dashboard & audit logs | ✅ | — | ✅ | — |

---

## Tech stack

**Frontend** Next.js 15 (App Router) · TypeScript · Tailwind CSS · shadcn/ui · Recharts · TanStack Query · Google Maps JS API
**Mobile** Flutter 3 · Riverpod · Dio · go_router · fl_chart · google_maps_flutter · local_auth
**Backend** Laravel 12 · PHP 8.3 · JWT (tymon) · Spatie Permission · L5-Swagger · Laravel Queue
**AI** Python 3.11 · FastAPI · Prophet · XGBoost · scikit-learn · Tesseract OCR · OpenAI
**Data** MySQL 8 · Redis 7 · S3-compatible object storage
**Infra** Docker · Nginx · Ubuntu 22.04 · Cloudflare · GitHub Actions

---

## Licence

MIT — see [LICENSE](LICENSE).
