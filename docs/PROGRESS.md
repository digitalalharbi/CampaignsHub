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
## Auth upgrade — Sanctum cookie SPA ✅ (ADR 0001, verified live)
- Migrated SPA auth from in-memory PAT to Sanctum stateful cookie/session (CSRF + withCredentials +
  stateful domains). PATs retained for non-browser clients via `POST /api/v1/auth/tokens`.
- CORS configured (supports_credentials). RequireAuth gate + session restore on load.
- Backend 15 tests green (6 auth incl. session + PAT). Live: guest→/login (401), login (csrf+session),
  refresh keeps session (`/auth/me` 200), logout — 0 console errors.

## Project management UI ✅ (edit/clone/team proven live; pause/archive/restore wired+tested)
- ProjectsPage full action set (edit modal, clone, pause/resume, archive/restore, show-archived,
  team). ProjectTeamPage add/remove members via GET /users. Live: clone 201, edit PATCH 200, team
  add 201 — all rendered, 0 console errors. Commit 30f5735.

## Project management + wider isolation 🚧 (Backend ✅ ahead of Frontend)
- **Backend (done + tested):** ProjectController CRUD + clone + archive/restore + pause/resume +
  statuses; project_memberships + team management (last-admin protection); BelongsToProject on Task
  + AppNotification; per-project routes (team/tasks/notifications). 61 backend tests.
- **Frontend (partial):** ProjectsPage create+archive; project Tasks card; project selector.
  **NOT yet proven live: edit, clone, restore, pause/resume, team management UI** — do not treat
  project-management UI as complete until these are demonstrated in the browser.
- **Live-proven so far:** multi-domain switch — project #1 shows bound account AND task; switching to
  project #2 empties BOTH (no leakage). Commits 52b456c, 6ba6f7f.
- Note: campaigns/reports/AI-context/cache/queue/websocket per-project isolation will be proven as
  those domains are built — the mechanism (ProjectContext + BelongsToProject + isolated query keys)
  is done and reused. Also flagged: CRM "Leads" is generic sales, not media-buying core (kept, but
  the operational core is projects + sources + client workspaces).

## Per-project integration bindings ✅ (priority #1, backend tested + UI live-proven)
- Full chain: Tenant→ClientWorkspace→Project→ProjectIntegrationBinding→ExternalAccount→
  ProviderConnection→IntegrationCredential (encrypted). 6 tables/models.
- ProjectContext + ResolveProject (route-only, fail-closed 404) + BelongsToProject + named
  ProjectScope; middleware priority resolves project before route binding.
- Sandbox wizard: connect→discover accounts→bind→sync; detach keeps other project + doesn't revoke;
  revoke disables all bindings; sharing needs confirm=true. 7 tests (57 backend total).
- Projects UI (/projects) + per-project integrations page with project selector (cache isolated).
- **Live-proven**: project A shows bound Sandbox ad account; switching to another project shows
  empty — bound accounts change with no leakage; 0 console errors. Commits 7ed31af, c50dbe1.
- Remaining priorities (client portal, notif/tasks/AI-BYOK UIs, invitations, platform admin,
  billing, demo tour, rest of media-buying journey) — next.

## CampaignsHub rebrand + rentable SaaS ✅ (backend tested; key UIs live)
- Brand: central config/brand.php + /api/v1/brand + SPA brand module; title/OG/schema/manifest/i18n.
- Client Workspaces (Managed/Collaborative/Self-Service) + Projects; AI BYOK (encrypted/masked/
  isolated); Notifications (per-recipient); Tasks (8-status). 10 new tests. **51 backend tests total.**
- Content protection: dynamic Watermark component + honest CONTENT_PROTECTION.md / WATERMARK_POLICY
  (no absolute screenshot-prevention claim).
- Marketing site `/welcome` (CampaignsHub) verified live; demo seed for all new domains.
- Docs: BRAND_GUIDELINES, DOMAIN_ARCHITECTURE, CLIENT_WORKSPACES, AI_BYOK_ARCHITECTURE,
  NOTIFICATION_ARCHITECTURE, TASK_MANAGEMENT, WATERMARK_POLICY, CLIENT_PORTAL, MARKETING_SITE,
  DEMO_GUIDE, DEMO_ACCOUNTS, LIVE_TEST_EVIDENCE.
- Remaining: client portal UI, platform-admin UI, plans/billing enforcement, invite flow, live
  integrations/AI calls, Playwright E2E — see KNOWN_LIMITATIONS.md.

## Phase 3 — CRM 🚧 (Leads vertical done & verified live)
- Backend: leads/companies/contacts/pipelines/opportunities/activities (tenant-scoped), actions
  (Create/Update/Convert/RecordActivity), controllers, resources, routes, permissions. 22 tests green.
- Fixed middleware ordering bug: ResolveTenant now runs before SubstituteBindings (tenant scope
  active during route-model binding). Regression test added (binding + convert with cleared context).
- Frontend: Leads list (filters + DataTable), create modal, convert action — wired to API.
- Verified live: list loads (200), create + convert (`/convert` 201) update UI to "converted",
  0 console errors, RTL Arabic. Screenshots captured.
- Remaining in Phase 3 (later): Lead detail + timeline UI, Companies/Contacts screens, Opportunities
  kanban, Proposals/Contracts, Onboarding, Client Portal.
## Phase 4 — Campaign Operations 🚧 (C1: Unified + External campaigns + linking done & verified)
- Architecture + ERD: `docs/CAMPAIGNS_ARCHITECTURE.md` (Campaigns/Metrics/Reports/Alerts, phase plan C1–C6).
- Backend domain `app/Domains/Campaigns`: `unified_campaigns` + `external_campaigns` migration (reversible);
  models (BelongsToTenant + BelongsToProject), `CampaignObjective`/`CampaignStatus` enums
  (`fromProvider()` normalization), resources, `CampaignLinker` service (link/unlink/duplicate-guard/
  name-similarity suggestions), `ImportExternalCampaigns` action.
- External campaigns are imported from the REAL Sandbox connector — wired into
  `ProjectIntegrationController@sync` (idempotent upsert per `(external_account_id, external_id)`);
  no fabricated rows.
- API (project-scoped, fail-closed): unified CRUD + pause/activate/archive; external list;
  link (409 `requires_confirmation` on move) / unlink / suggestions; project-wide external-campaigns list.
- Uses existing `campaigns.*` permissions (view/create/update/pause). Server-side RBAC enforced.
- `CampaignTest`: 12 tests (CRUD, real-sync import, idempotency, link/unlink, 409 move-guard,
  suggestions, RBAC 403, per-project + cross-tenant isolation). Full suite **75 green**; Pint + Larastan clean.
- Remaining in Phase 4 (later): ad groups/ads/creatives (C2), campaign detail/notes/activity UI,
  live budget/status writes via connectors (permissioned + audited).
## Phase 5 — Tracking & Ecommerce ⬜
## Integrations architecture ✅ (Awaiting Credentials; 41 backend tests incl. contract tests)
- Unified `AdvertisingConnector` + `AwaitingCredentialsConnector` base (never fabricates success)
- Sandbox connector (deterministic, labelled, non-prod) + 6 platform stubs
- `ConnectorRegistry`, `Integration` model (encrypted-credentials column), status/health/connect/sync API
- Contract test across all connectors + feature tests; live status board UI

## Phase 6 — Advertising Integrations ⏳ (needs platform credentials; Sandbox connectors first)
## Phase 7 — Analytics & Reports ⬜
## Phase 8 — AI & MCP ⬜
## Phase 9 — Billing ⏳ (Tap/Moyasar sandbox)
## Phase 10 — Hardening ⬜

---

## Evidence entries
_(append newest first: date — what — command — result)_

- 2026-07-21 — Laravel 12 scaffold — `php artisan --version` → `Laravel Framework 12.64.0`.

- 2026-07-24 — Campaigns C1 (Unified+External+linking) — `php artisan test` → 75 passed (338 assertions); `pint --test` passed; `phpstan` No errors; migrate rollback+fresh clean.

- 2026-07-26 — Reports premium chart design system (commit `14ebde1`) — shared `charts.tsx`
  (line/area/donut/ranking-bar/funnel/progress-ring/sparkline, RTL+dark, ResponsiveContainer, no
  data logic inside). Rebuilt interactive slides: executive summary, per-platform performance charts,
  visual creative cards, platform comparison, funnel, budget pacing. `MetricsAggregator.timeseriesByProvider`,
  `ReportGenerator` emits platform_series+best+funnel+budget. Verified live: 67 chart surfaces in one
  report, 0 console errors. Gates: backend 106 passed, pint+phpstan clean, tsc/lint/build ok.

- 2026-07-26 — Central disclaimer & methodology system (commit `be1dc16`) — two-level bilingual copy
  (full/short) resolved project→client→org→system (`config/disclaimers.php` base). `disclaimers` table +
  `DisclaimerResolver`; snapshotted into every report's data; PDF final methodology page + per-page short
  footer; XLSX 'Methodology & Notes' sheet; CSV metadata block; settings API (versioned+audited, 403-gated);
  live per-project resolve endpoint. Frontend `PerformanceNotice` (compact/full/footer/tooltip/methodology,
  RTL/LTR+dark, 320px-safe) wired into dashboard, analytics, interactive report, client link.
  Gates: backend **111 passed (445)**, pint+phpstan clean, tsc/lint/build ok, vitest 18 passed; PDF/XLSX/CSV
  export smoke each carries methodology; verified live (dashboard note, report methodology+footer, 0 errors).
