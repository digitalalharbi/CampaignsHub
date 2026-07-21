# Build Progress & Evidence Log

This log records what is actually built and **verified**, versus pending. No item is marked done
without evidence (command output / test result / screenshot).

## Legend
- ✅ done & verified  · 🚧 in progress · ⬜ not started · ⏳ awaiting external credentials

---

## Phase 0 — Discovery ✅
- Environment inspected: PHP 8.4.23, Composer 2.10, Node 24.15, PostgreSQL 16.14, Redis 8.8, Git
  2.50. Docker not installed. Target folder was empty (greenfield).
- Decision: foundation-first; build inside `MediaBying System/`; Laravel pinned to **12** per spec.

## Phase 1 — Foundation ✅ (verified end-to-end)
- ✅ Git repo + monorepo structure (`backend/ frontend/ infrastructure/ docs/ .claude/`).
- ✅ Laravel 12.64 API-only on PostgreSQL 16 + Redis (predis) + Sanctum.
- ✅ Response envelope + request-id middleware + JSON exception handler; `/api/v1/health` + `/ready`.
- ✅ Domain skeleton (`app/Domains/{Identity,Tenancy,Access,Audit}`) + multi-tenancy
  (TenantContext, global TenantScope, BelongsToTenant, ResolveTenant) with 5 isolation tests.
- ✅ RBAC (roles/permissions/pivots + HasRoles) + append-only Audit Log + auth listeners.
- ✅ Auth: register (provisions tenant+workspace+owner), login, me, logout.
- ✅ React 19 + TS(strict) + Vite frontend: design tokens (light/dark), App Shell (sidebar/topbar),
  RTL/LTR, theme toggle, TanStack Query + Axios client, login page, live system-status dashboard.
- ⬜ Docker Compose authored; NOT locally runnable (no Docker on machine) — verify before use.
- ⬜ CI pipeline (next).

### Phase 1 evidence (2026-07-21)
- Backend gate: `php artisan test` → **15 passed (51 assertions)** incl. 5 tenant-isolation;
  `pint` → passed; `phpstan` (larastan, level 5) → **No errors**.
- Live HTTP: `/api/v1/health` 200 + `X-Request-Id`; `/ready` → `database:up, redis:up`;
  `POST /auth/register` provisions tenant + returns token.
- Frontend gate: `tsc -b` clean; `oxlint` exit 0; `vitest` → **4 passed**; `vite build` OK.
- Live browser (localhost:5173 → proxy → API): dashboard shows real health/ready data in Arabic RTL
  and English dark-mode LTR (0 console errors); `POST /api/v1/auth/login → 200` then redirect to the
  authenticated shell. Screenshots captured in the build session.

## Phase 2 — Design System ✅ (core library, verified live)
- ✅ Form primitives: Field, Input, Textarea, Select, Checkbox, Switch (token-based, RTL-aware).
- ✅ UI states: Skeleton, EmptyState, ErrorState, NoPermission.
- ✅ DataTable: client search + sortable columns + pagination + loading/empty/error, sticky header,
  horizontal scroll, Latin tabular numbers.
- ✅ Overlay/nav: Modal (focus-trap + Escape + click-outside), Tabs, Alert (icon + text severity).
- ✅ `/design` showcase route + sidebar entry.
- Gate: `tsc` clean, `oxlint` clean, `vitest` 4 passed, `vite build` OK. Verified live in browser:
  components in Arabic RTL (light) and DataTable in English/dark — 0 console errors.
- ⬜ Later: Command Palette (⌘K), Toast provider, Date range picker, mobile card fallback for tables.
## Phase 3 — CRM ⬜
## Phase 4 — Campaign Operations ⬜
## Phase 5 — Tracking & Ecommerce ⬜
## Phase 6 — Advertising Integrations ⏳ (needs platform credentials; Sandbox connectors first)
## Phase 7 — Analytics & Reports ⬜
## Phase 8 — AI & MCP ⬜
## Phase 9 — Billing ⏳ (Tap/Moyasar sandbox)
## Phase 10 — Hardening ⬜

---

## Evidence entries
_(append newest first: date — what — command — result)_

- 2026-07-21 — Laravel 12 scaffold — `php artisan --version` → `Laravel Framework 12.64.0`.
