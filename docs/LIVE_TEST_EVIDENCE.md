# Live Test Evidence

Captured during the build (in-browser, real servers). Backend on :8000, SPA on :5173 (Vite proxy).

## Verified live (zero console errors on each)
- **Brand**: `GET /api/v1/brand` → `CampaignsHub / campaignshub.io`; browser tab title =
  "CampaignsHub — Run every client, project, and campaign from one place".
- **Marketing** `/welcome`: hero + problem/solution + features + pricing + security (with honest
  no-screenshot-prevention note) + FAQ + footer; RTL Arabic; brand green.
- **Auth (Sanctum cookie SPA)**: guest → `/login` (401 on `/auth/me`); login (csrf → session);
  **session survives reload** (`/auth/me` 200); logout.
- **CRM Leads**: list loads (`GET /leads` 200); create modal; **convert** (`POST /leads/{id}/convert`
  → 201) updates the row to "converted".
- **Integrations**: 7 connector cards — Sandbox "connected", 6 platforms "awaiting credentials"
  (honest; no fake success).
- **Design system** `/design`: components in Arabic RTL (light) + DataTable in English (dark).

## Automated (headless)
- Backend: **51 tests / 191 assertions** on PostgreSQL (Pint + Larastan level 5 clean).
- Frontend: tsc strict + oxlint clean, Vitest 4 passing, production build OK.
- Archive: extract + composer install + migrate:fresh --seed + full test suite + frontend build all
  pass clean-room (see `TEST_RESULTS.md`).

## Bugs found via live/archive testing (fixed + regression-tested)
1. Middleware order — `ResolveTenant` must precede `SubstituteBindings` (else tenant-scoped route
   binding 404s; fail-closed correctness). Fixed via middleware priority.
2. `.env.example` missing `SANCTUM_STATEFUL_DOMAINS` broke session auth on a clean install. Fixed.

## Not yet automated
Playwright E2E (`/demo-tour` journeys), visual regression, accessibility automation. See
`KNOWN_LIMITATIONS.md`.
