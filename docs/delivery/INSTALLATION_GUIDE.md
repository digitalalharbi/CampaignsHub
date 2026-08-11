# Installation Guide — CampaignsHub

Local, from a clean checkout. Requirements: PHP 8.4 (+ext pgsql), Composer, Node 20+, PostgreSQL 14+.

## 1. Backend
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Set DB_* in .env to your Postgres, then:
createdb campaignshub            # or via your PG client
php artisan migrate --seed       # schema + demo data (owner/analyst/viewer)
```

## 2. Frontend
```bash
cd frontend
npm ci
```

## 3. Run (three processes)
```bash
# terminal 1 — API (concurrent workers)
cd backend && PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload      # :8000
# terminal 2 — queue worker (report generation, async work)
cd backend && php artisan queue:work --tries=3
# terminal 3 — scheduler (scheduled reports, alerts, SLA)  [or a system cron running schedule:run every minute]
cd backend && php artisan schedule:work
# terminal 4 — SPA
cd frontend && npm run dev                                                 # :5173 (proxies /api → :8000)
```
Open http://localhost:5173.

## Demo credentials (seeded)
| Role | Email | Password |
|---|---|---|
| Owner | `agency@campaignshub.io` | `password` |
| Analyst | `analyst@demo-agency.local` | `password` |
| Viewer | `viewer@demo-agency.local` | `password` |

## Tests
```bash
cd backend  && php artisan test                 # backend suite
cd frontend && npm run build                    # typecheck + production build
cd frontend && npx playwright test              # E2E (starts both servers itself)
```

## External integrations (Awaiting Credentials — do not block local use)
Email / WhatsApp / SMS delivery and Google OAuth are adapter-based and inert until you add credentials. The
whole system runs and every internal flow is testable without them; nothing is ever logged as `sent` before a
real provider is wired. See `docs/PRODUCTION_RUNBOOK.md` for wiring.
