# Deployment Guide

> Docker files and CI are authored but **not yet run** (no Docker on the build machine, CI unrun).
> Treat commands below as the intended procedure and validate in staging first.

## Environments
Keep four separate configs: `local`, `testing`, `staging`, `production`. Never share secrets or
databases across them. Set `APP_ENV`, `APP_DEBUG=false` (staging/prod), and real
`SANCTUM_STATEFUL_DOMAINS` / `FRONTEND_URL` / CORS origins per environment.

## Option A — Docker Compose (single host / staging)
```bash
# From repo root
cp backend/.env.example backend/.env      # then fill real values (never commit)
cp frontend/.env.example frontend/.env
docker compose build
docker compose up -d
docker compose exec backend php artisan migrate --force
```
Services: `postgres`, `redis`, `backend` (:8000), `queue` (Horizon), `scheduler`, `frontend` (nginx :5173).

## Option B — Manual / PaaS
### Backend
```bash
cd backend
composer install --no-dev --optimize-autoloader
php artisan key:generate           # once, store in secret manager
php artisan config:cache route:cache
php artisan migrate --force
php artisan queue:work --queue=critical,payments,webhooks,default   # or php artisan horizon
php artisan schedule:work           # cron: * * * * * php artisan schedule:run
```
Serve via php-fpm behind nginx (not `artisan serve`) in production.

### Frontend
```bash
cd frontend
npm ci && npm run build             # outputs dist/
# serve dist/ via nginx (see infrastructure/nginx/frontend.conf); proxy /api + /sanctum to backend
```

## Post-deploy checks
- `GET /api/v1/health` → 200; `GET /api/v1/ready` → db + redis `up`.
- Login from the SPA works (csrf-cookie then login); session persists on reload.
- Queue worker / Horizon dashboard healthy; scheduler running.
- Security headers present; `APP_DEBUG=false`; no stack traces leaked.

## Rollback
- Code: deploy the previous commit/tag (`git log --oneline`); rebuild.
- DB: only run reversible migrations; take a snapshot before `migrate --force` (see
  `BACKUP_RESTORE_GUIDE.md`). To undo the last batch: `php artisan migrate:rollback`.

## Zero-downtime notes
Run migrations that are additive/backwards-compatible first, deploy code, then clean up in a later
release. Cache config/routes after each deploy; clear on rollback.
