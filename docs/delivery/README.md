# CampaignsHub

A multi-tenant media-buying SaaS: run clients, projects, ad accounts, campaigns, content, tracking,
reporting, alerts, and external client requests from one place. Arabic-first (RTL), installable PWA.

- **Backend:** Laravel 12 (PHP 8.4), DDD, PostgreSQL, Sanctum SPA cookie auth, fail-closed multi-tenancy.
- **Frontend:** React 19 + TypeScript (strict) + Vite, TanStack Query, react-router, zustand.

## Start here
1. `INSTALLATION.md` — set up and run locally (backend + queue worker + scheduler + frontend).
2. `PRODUCTION_RUNBOOK.md` — deploy + operate (processes, provider wiring, health).
3. `DEPLOYMENT_CHECKLIST.md` — pre/post-deploy gates.
4. `API_DOCUMENTATION.md` + `CampaignsHub.postman_collection.json` — the 207-endpoint API.
5. `DATABASE_ERD.md` — schema + relationships.
6. `SECURITY_AUDIT.md` — isolation, authz, secrets, transport.
7. `FINAL_TEST_RESULTS.md` — certified backend + 3-browser E2E results.
8. `OPEN_EXTERNAL_DEPENDENCIES.md` — the only pending items (all external credentials).

## Demo credentials (seeded)
`owner@demo-agency.local` / `analyst@demo-agency.local` / `viewer@demo-agency.local` — password `password`.

## Honest status
Email / WhatsApp / SMS / Google OAuth / ad-platform sync are **Awaiting Credentials** — the system runs and all
internal flows work without them; nothing is logged as `sent` before a real provider answers.
