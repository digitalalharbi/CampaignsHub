#!/usr/bin/env bash
# Bring up the FULL CampaignsHub dev environment on fixed ports and verify it — not just the frontend.
# Frontend http://localhost:5173 · Backend http://127.0.0.1:8000
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BE="$ROOT/backend"; FE="$ROOT/frontend"
RUN="$BE/storage/dev"; mkdir -p "$RUN"
say(){ printf '\033[1m== %s\033[0m\n' "$*"; }

# 0) Clear zombies on our fixed ports + stale workers (never reuse old processes).
say "clearing stale processes / ports"
lsof -ti tcp:8000 | xargs kill -9 2>/dev/null
lsof -ti tcp:5173 | xargs kill -9 2>/dev/null
pkill -9 -f "artisan serve" 2>/dev/null
pkill -9 -f "queue:work" 2>/dev/null
pkill -9 -f "schedule:work" 2>/dev/null
pkill -9 -f "vite" 2>/dev/null
sleep 1

# 1) Dependencies: PostgreSQL + Redis must be up (do not start system services silently — report).
say "checking datastores"
if ! (cd "$BE" && php artisan db:show >/dev/null 2>&1); then
  echo "  PostgreSQL: attempting brew service…"; brew services start postgresql@14 >/dev/null 2>&1 || brew services start postgresql >/dev/null 2>&1; sleep 3
fi
redis-cli ping >/dev/null 2>&1 || { echo "  Redis: attempting brew service…"; brew services start redis >/dev/null 2>&1; sleep 2; }
redis-cli ping >/dev/null 2>&1 && echo "  Redis: OK" || echo "  Redis: DOWN"

# 2) Migrate (idempotent) so the schema is current.
say "migrate"
(cd "$BE" && php artisan migrate --force >/dev/null 2>&1) && echo "  migrations: OK" || echo "  migrations: check"

# 3) Backend API (concurrent workers, no reload).
say "backend :8000"
(cd "$BE" && PHP_CLI_SERVER_WORKERS=4 nohup php artisan serve --no-reload --host=127.0.0.1 --port=8000 >"$RUN/backend.log" 2>&1 &)
# 4) Queue worker (reports,default) + Scheduler.
say "queue worker (reports,default) + scheduler"
(cd "$BE" && nohup php artisan queue:work --queue=reports,default --tries=3 --timeout=900 --sleep=1 >"$RUN/queue.log" 2>&1 &)
(cd "$BE" && nohup php artisan schedule:work >"$RUN/scheduler.log" 2>&1 &)
# 5) Frontend dev server (Vite HMR).
say "frontend :5173 (Vite HMR)"
(cd "$FE" && nohup npm run dev >"$RUN/frontend.log" 2>&1 &)

# 6) Wait for health.
say "waiting for health"
for i in $(seq 1 30); do curl -sf -o /dev/null http://127.0.0.1:8000/up && break; sleep 1; done
for i in $(seq 1 40); do curl -sf -o /dev/null http://localhost:5173/ && break; sleep 1; done

# 7) Verify (acceptance).
say "verify"
BE_OK=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000/up)
FE_OK=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:5173/)
echo "  backend /up: $BE_OK   frontend /: $FE_OK"
echo "  queue test job:"; (cd "$BE" && php artisan dev:queue-ping --timeout=15 2>&1 | sed 's/^/    /')
echo "  dev status:"; curl -s http://127.0.0.1:8000/api/v1/dev/status | sed 's/^/    /' | head -c 600; echo
echo ""
say "UP — open http://localhost:5173  (dev status: http://localhost:5173/dev/status)"
