# Handover — MediaBuying Platform

_Last updated: 2026-07-21._

This document is an **honest** account of what exists, what is verified, and what remains. It does
not claim the full platform is complete. The scope in the original brief is a multi-month,
multi-engineer build; this handover covers the foundation and the first operational verticals,
built to production-grade quality with tests and evidence at each step.

## What is built and verified ✅

| Area | State | Evidence |
|---|---|---|
| Monorepo + tooling | ✅ | `backend/` (Laravel 12.64), `frontend/` (React 19 + Vite), `infrastructure/`, `docs/` |
| API foundation | ✅ | Envelope, request-id, JSON exception handler, `/api/v1/health` + `/ready` |
| PostgreSQL + Redis | ✅ | Migrations run; `/ready` reports db + redis up |
| Multi-tenancy | ✅ | TenantContext + global scope + `BelongsToTenant`, **fail-closed**; isolation tests |
| RBAC + Audit | ✅ | Roles/permissions/policies checks; append-only audit log |
| Auth (Sanctum cookie SPA) | ✅ | CSRF + session; session survives refresh; PAT endpoint for API clients (ADR 0001) |
| Design system | ✅ (core) | Tokens (light/dark), App Shell, RTL/LTR, Button/Card/Badge/Table/Modal/Tabs/Alert/Forms/States |
| CRM — Leads vertical | ✅ | Leads CRUD + convert→opportunity (company+contact), timeline, permissions; live UI |
| Integrations architecture | ✅ | Unified connector + Sandbox + 6 real stubs (Awaiting Credentials); contract tests |

Backend: **41 tests passing**. Frontend: **4 tests passing** + typecheck/lint/build clean.
See `TEST_RESULTS.md`.

## What is NOT built yet ⬜ (remaining scope)

Phases 4–10 from the brief are **not implemented**: full Media Planning, Content & Approvals,
Campaign Launch workflow, Tracking/Attribution (GA4/GTM/pixels/CAPI), Salla/Zid, live advertising
platform sync, Analytics/Reports engine, AI/MCP/Automation, Billing (Tap/Moyasar), Client Portal,
Proposals/Contracts/Onboarding UI, Reverb realtime, Playwright/visual-regression suites. The
advertising/ecommerce/payment connectors exist as **interfaces + sandbox + stubs** and are marked
`Awaiting Credentials` — they are not live. See `KNOWN_LIMITATIONS.md`.

## Run it locally
See `README.md`. TL;DR:
```bash
createdb mediabuying && createdb mediabuying_test
cd backend && cp .env.example .env && php artisan key:generate && php artisan migrate --seed && php artisan serve
cd ../frontend && cp .env.example .env && npm install && npm run dev
```
Demo login: `owner@demo-agency.local` / `password`.

## Quality gates
- Backend: `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse --memory-limit=512M`, `php artisan test`.
- Frontend: `npm run typecheck`, `npm run lint`, `npm run test`, `npm run build`.
- Combined: `bash .claude/hooks/quality-gate.sh`.

## Key decisions
Architecture decisions are recorded in `docs/architecture-decisions/`. Notable:
- ADR 0001 — Sanctum cookie SPA auth.
- Email is the global login id (documented deviation from tenant-scoped uniqueness).
- Middleware priority ensures tenant resolves before route-model binding (fail-closed correctness).

## Environment / credentials
No secrets are committed. `.env.example` files list required variables.
External integrations need credentials — see `INTEGRATION_CREDENTIALS_CHECKLIST.md`.

## Companion documents
`TEST_RESULTS.md` · `SECURITY_REVIEW.md` · `PRODUCTION_READINESS_CHECKLIST.md` ·
`KNOWN_LIMITATIONS.md` · `DESIGN_SYSTEM_REPORT.md` · `DEPLOYMENT_GUIDE.md` ·
`BACKUP_RESTORE_GUIDE.md` · `INTEGRATION_CREDENTIALS_CHECKLIST.md` · `docs/PROGRESS.md`.
