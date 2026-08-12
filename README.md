# CampaignsHub

Multi-tenant SaaS for running paid media end to end — leads and onboarding, media planning, content
approval, campaign launch, cross-platform sync, tracking and attribution, optimisation, client
reporting and billing.

منصة CampaignsHub لإدارة الحملات الإعلانية بالكامل من مكان واحد: من العميل المحتمل حتى التقرير
النهائي والفاتورة.

## Four portals, one sign-in

Everybody signs in at **`/login`**. The backend decides where they land from their account state,
membership, role, permissions and portal — never from the address they typed.

| Path | Who it is for |
|---|---|
| `/admin` | Platform owner — plans, providers, readiness, operations. Belongs to no tenant (ADR 0002) |
| `/app` | Advertiser — a company running its own campaigns |
| `/agency` | Agency — the team running campaigns for other people |
| `/portal` | Client — the customer's own read-mostly view |

## Monorepo layout

```
backend/          Laravel 12 REST API (API-only, /api/v1), DDD under app/Domains/*
frontend/         React 19 + TypeScript (strict) + Vite SPA
infrastructure/   docker/, nginx/, scripts/
docs/             architecture, decisions, runbooks, handoff
docker-compose.yml
```

## Tech stack

- **Backend** — Laravel 12, PHP 8.4, PostgreSQL 16, Redis (sessions, cache, queues), Sanctum
  cookie sessions for the SPA, Horizon.
- **Frontend** — React 19, TypeScript strict, Vite, TanStack Query, Tailwind v4, shadcn-style UI.
- **Architecture** — modular monolith with domain packages, Arabic-first and fully bilingual (RTL/LTR).

## Local prerequisites

PHP 8.4, Composer, Node 20+, PostgreSQL 16, Redis. Docker files are provided; Docker is optional
locally.

## Quick start (local, without Docker)

```bash
createdb mediabuying          # development
createdb mediabuying_test     # the suite's own database — phpunit.xml points at it

cd backend
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve                 # http://127.0.0.1:8000

cd ../frontend
cp .env.example .env
npm install
npm run dev                       # http://127.0.0.1:5173
```

Development sign-ins are listed in [`docs/DEMO_ACCOUNTS.md`](docs/DEMO_ACCOUNTS.md). They are seeded
in local/testing only and are never rendered in the product.

## Verifying

```bash
cd backend  && php artisan test && vendor/bin/pint
cd frontend && npm run typecheck && npm test && npm run lint && npm run build
cd frontend && npm run gate       # Playwright, one isolated run per browser
```

`php artisan test` runs against **`mediabuying_test`** (see `backend/phpunit.xml`) and does not create
it — hence the second `createdb` above. The gate's database, `mediabuying_e2e`, IS created and reset
for you by `php artisan e2e:prepare` on every run, so there is nothing to do for that one.

## Health

- `GET /api/v1/health` — liveness
- `GET /api/v1/ready` — readiness (database, redis)

## Documentation

Start with [`HANDOFF_MANIFEST.md`](HANDOFF_MANIFEST.md) — it is the map for a developer taking this
over. Then [`docs/PRODUCTION_HANDOFF.md`](docs/PRODUCTION_HANDOFF.md),
[`docs/DEPLOYMENT_CHECKLIST.md`](docs/DEPLOYMENT_CHECKLIST.md) and
[`docs/INTEGRATION_CREDENTIALS_CHECKLIST.md`](docs/INTEGRATION_CREDENTIALS_CHECKLIST.md).
[`CLAUDE.md`](CLAUDE.md) holds the engineering rules the code was written under.

## Status

The product is built and tested. **No external provider is `LIVE_VERIFIED`** — no credential exists
yet for any of the eleven (six ad platforms, Salla, Zid, Moyasar, mail, FX). Each is
`READY_FOR_CREDENTIALS`, `READY_FOR_CONFIGURATION` or `BLOCKED_EXTERNAL_CREDENTIALS`, and the
product says so on its own surfaces rather than showing a connection it does not have. See
`HANDOFF_MANIFEST.md` §Integration readiness and `KNOWN_LIMITATIONS.md`.
