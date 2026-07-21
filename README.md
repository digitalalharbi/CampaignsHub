# MediaBuying Platform

Multi-tenant SaaS for managing and automating the full media-buying lifecycle — from lead
acquisition through onboarding, media planning, content approval, campaign launch, cross-platform
sync, tracking & attribution, optimization, reporting, and billing.

منصة SaaS متعددة العملاء لإدارة وأتمتة رحلة الميديا باينج بالكامل من مكان واحد.

## Monorepo layout
```
backend/          Laravel 12 REST API (API-only, /api/v1)
frontend/         React + TypeScript + Vite SPA (decoupled)
infrastructure/   docker/, nginx/, scripts/
docs/             architecture, api, domains, design system, runbooks
docker-compose.yml
```

## Tech stack
- **Backend**: Laravel 12, PHP 8.3+, PostgreSQL 16, Redis, Sanctum, Horizon.
- **Frontend**: React 19, TypeScript (strict), Vite, TanStack Query, Tailwind, shadcn-style UI.
- **Architecture**: Modular Monolith + DDD (`app/Domains/*`).

## Local prerequisites
PHP 8.3+, Composer, Node 20+, PostgreSQL 16, Redis. (Docker files are provided but Docker is
optional locally.)

## Quick start (local, without Docker)
```bash
# 1) Database
createdb mediabuying              # PostgreSQL must be running

# 2) Backend
cd backend
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve                 # http://127.0.0.1:8000

# 3) Frontend
cd ../frontend
cp .env.example .env
npm install
npm run dev                       # http://127.0.0.1:5173
```

## Health
- `GET /api/v1/health`  — liveness
- `GET /api/v1/ready`   — readiness (db, redis)

## Documentation
See [`docs/`](docs/) — start with [`docs/PROGRESS.md`](docs/PROGRESS.md) for build status and
[`CLAUDE.md`](CLAUDE.md) for engineering rules.

## Status
🚧 Under active construction, built phase-by-phase with evidence at each quality gate.
Nothing here claims to be production-complete. See `docs/PROGRESS.md` and `KNOWN_LIMITATIONS.md`.
