# CampaignsHub — session bootstrap (READ FIRST, every new session)

On starting ANY new session in this repo, do this BEFORE anything else, then resume automatically:
1. Read `docs/RESUME_STATE.md` (authoritative handoff: branch, HEAD, WIP, Exact Next Task, commands).
2. Read `docs/MASTER_REQUIREMENTS.md`, `docs/IMPLEMENTATION_MATRIX.md`, `docs/OPEN_GAPS.md`.
3. Run `git status`, `git log --oneline -8`, `git worktree list`.
4. Bring up / check services: `bash scripts/dev-up.sh` then `bash scripts/dev-status.sh` (preview http://localhost:5173, backend http://127.0.0.1:8000, /dev/status).
5. Resume from **Exact Next Task** in RESUME_STATE. Do NOT redo completed/committed work. Do NOT ask the user. Do NOT send interim/progress updates.

## Hard rules
- The delivered release is FROZEN: tag `v1.1.0-expanded-final` (`e9b99f2`) + `~/Desktop/CampaignsHub-*-Delivery.zip` are UNTOUCHABLE. Active dev is on branch `feat/taxonomy-ux`.
- **HEAD `aaa79da` holds an UNVERIFIED agent WIP snapshot** — verify (frontend `npm run build && npx vitest run`; backend `php artisan test`; re-seed taxonomy) and commit a clean commit BEFORE building on it. Never claim untested work complete.
- Taxonomy engine option keys for enum-backed fields MUST equal the LIVE backend enum values (source of truth). Never reintroduce aspirational keys. Used options are DEACTIVATED/merged, never deleted. No data loss.
- Honest states: nothing logged sent/connected/paid without a real verified provider (Email/WhatsApp/SMS/Payment/Ad-platforms/Drive = Awaiting Credentials).
- Dev-only secrets (OTP dev_code, portal dev token, /dev/status) are hard-gated off in production.

## Stack
Backend: Laravel 12 (PHP 8.4), PostgreSQL, Redis, Sanctum SPA cookie auth, DDD `app/Domains/*`. Frontend: React 19 + TS + Vite, TanStack Query, react-router, zustand. Arabic-first RTL, Latin digits. Demo logins in `docs/delivery/DEMO_ACCESS.md` (password `password`).

## Run / test
- Env: `bash scripts/dev-up.sh` (backend workers=4 + queue:work reports,default + scheduler + Vite). `.env` local `E2E_RELAX_RATE_LIMITS=true` (local only; prod/staging/testing throttle).
- Backend tests: `cd backend && php artisan test`. Frontend unit: `cd frontend && npx vitest run`. E2E: `cd frontend && CI=1 npx playwright test`.
- Reset dev DB: `cd backend && php artisan migrate:fresh --seed --force`.
