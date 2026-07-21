# Production Readiness Checklist

`[x]` done · `[~]` partial · `[ ]` not started. Honest as of 2026-07-21.

## Architecture & code
- [x] Laravel 12 API-only; decoupled React 19 + TS strict SPA
- [x] Modular Monolith / DDD (`app/Domains/*`); thin controllers → Actions/Services → Resources
- [x] Standard response envelope, request/correlation id
- [x] External SDKs behind interfaces (connectors)
- [~] All domains implemented (Identity/Tenancy/Access/Audit/CRM/Integrations done; rest pending)

## Data & multi-tenancy
- [x] PostgreSQL; UUIDs on API entities; NUMERIC money; TIMESTAMPTZ; JSONB
- [x] `tenant_id` on operational rows; global scope; **fail-closed**; isolation tests
- [x] Tenant-scoped unique constraints; cache/session use Redis
- [ ] Materialized views / partitioning / pgvector (analytics/AI phases)

## Security
- [x] Sanctum cookie SPA auth (CSRF, withCredentials, stateful domains); PAT for API clients
- [x] Server-side authorization (permissions), not button-hiding
- [x] No secrets in git; `.env.example` only; encrypted credentials column for integrations
- [x] Security headers (nginx) + framework/version hiding
- [~] CORS locked to SPA origins (dev config; set production origins)
- [ ] 2FA, email verification enforcement, dependency scanning, pen-test
- See `SECURITY_REVIEW.md`.

## Reliability & ops
- [x] Health/ready endpoints
- [~] Queues/Horizon configured (Redis) — not exercised under load
- [ ] Reverb realtime, scheduler jobs, webhook processing, reconciliation
- [ ] Load testing, N+1 audit at scale, index review under real data

## Quality gates
- [x] Backend: Pint + Larastan(5) clean; 41 tests green (PostgreSQL)
- [x] Frontend: tsc strict + oxlint clean; vitest green; production build OK
- [ ] Playwright E2E, visual regression, a11y automation

## Delivery
- [x] Docker/compose + nginx authored (unverified — no Docker locally)
- [x] CI workflow authored (unrun)
- [x] Handover docs, ADRs, progress log
- [x] Backup/restore + deployment guides
- [ ] Staging/production environments provisioned

## Go-live blockers (must clear before real launch)
1. Implement + credential the required integrations (see `INTEGRATION_CREDENTIALS_CHECKLIST.md`).
2. Build the remaining operational phases (4–10).
3. Run Docker/CI on real infra; add E2E/visual/a11y suites.
4. Security hardening (2FA, scanning, pen-test) and load testing.
