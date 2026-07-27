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

- 2026-07-26 — **Reports Core: Completed · Reports Hardening: In Progress** — merged to `feat/premium-ui`** (`44613ca`; branch `feat/reports-rebuild`
  ff-merged, 11 commits). Rebuilt the report output engine + data integrity + client audience:
  • Data validation gate — export blocked on inconsistency (e.g. results>0 with spend=revenue=0).
  • **Headless-Chromium PDF engine** replacing Dompdf for creative reports: React print route rendering
    the same SlideBody, correct Arabic RTL + embedded fonts + `<bdi>` numerals, real charts.
  • Print layout engine: `height:100vh` + SlideContentFitter (readable floor 0.85), honest content-
    utilization (grid over real content, not background), fixes the 11-slides→17-pages spill; hard gate
    on overflow / horizontal-clip / empty / clipped-elements / unreadable-scale.
  • Verified from the ACTUAL PDF (pdfplumber): page-count==slides, EXACT numeric parity (not just
    compact), provenance in `/Title`; per-page PNG + contact sheet audit.
  • **Audience separation client / internal / executive** enforced backend-side on EVERY export path
    (admin/scheduled/email/share) via `ReportExporter` — approved-recs-only, client names, no internal
    fields; `ClientReportContentValidator`; internal reports can't be shared or emailed externally
    (`ReportDeliveryAudienceGuard`); audience-specific XLSX sheets; executive = 6-page decision subset.
  • Two-column notes/recommendations, Next-Steps slide (approved only), single-item chart fallbacks.
  Gates at merge: backend **139 tests**, pint+phpstan clean, migrate:fresh --seed clean, tsc/lint/build,
  vitest 18. Follow-ups: automated visual-regression baselines, full annotation review UI
  (reviewed_by/approved_by), `client_display_name` DB fields. See docs/PDF_VISUAL_AUDIT.md.

- 2026-07-26 — Integrated Settings section (commits `866e66b`, `9328efa`) — tabbed shell replacing the
  placeholder route. **General** (org profile → tenant.settings, validated+audited). **Disclaimer**
  management (bilingual per-section editor, live preview, restore, versioned). **Team & Permissions**
  (invite/role/enable-disable/remove with server-side last-Owner & self guards). **Notifications**
  (per-user channels/categories/quiet-hours/frequency). **Security** (password, real RFC-6238 TOTP 2FA,
  sign-in history+devices from the audit trail, org policy). **Branding** (colors/logo/footer + live
  preview). **Clients** (create/rename/archive). **Projects** (create/archive via existing project API).
  No placeholders. Gates: backend **121 tests**, pint+phpstan clean, migrations reversible, tsc/lint/
  build ok, vitest 18. Verified live: all 8 tabs render, real data, guards enforced, 0 app errors.

- 2026-07-26 — Central disclaimer & methodology system (commit `be1dc16`) — two-level bilingual copy
  (full/short) resolved project→client→org→system (`config/disclaimers.php` base). `disclaimers` table +
  `DisclaimerResolver`; snapshotted into every report's data; PDF final methodology page + per-page short
  footer; XLSX 'Methodology & Notes' sheet; CSV metadata block; settings API (versioned+audited, 403-gated);
  live per-project resolve endpoint. Frontend `PerformanceNotice` (compact/full/footer/tooltip/methodology,
  RTL/LTR+dark, 320px-safe) wired into dashboard, analytics, interactive report, client link.
  Gates: backend **111 passed (445)**, pint+phpstan clean, tsc/lint/build ok, vitest 18 passed; PDF/XLSX/CSV
  export smoke each carries methodology; verified live (dashboard note, report methodology+footer, 0 errors).

- 2026-07-26 — Reports Hardening complete (`7fa324d` client_display_name · `cc70fbf` visual-regression
  harness + baselines · `8a8cee0` persisted approval workflow · `3a071dc` approval panel UI). Merged to
  `feat/premium-ui`. **Reports phase = Completed.** Post-merge gates: backend 142 tests, pint+phpstan
  clean, migrate:fresh --seed clean, tsc/lint/build, vitest 18, PDF visual-regression PASS.

---

## PDF deliverable hardening (2026-07-26)

- **Arabic PDF: Completed.** Root defect was the *text layer* (Chromium emits Arabic presentation
  forms in visual order), not visuals. Fixed via `page.pdf({tagged,outline})` + a fail-closed
  ToUnicode→NFKC normaliser (`fix-arabic-textlayer.py`, ≤3 passes, byte-idempotent, validates 0
  presentation forms remaining / ToUnicode+pages+tagged preserved / ASCII untouched; throws to block
  export otherwise). Verified in PDFKit (Preview/Safari/Quick Look/iOS) — 8/8 probe words incl.
  ligature/hamza; Chrome/PDFium loads all pages. Client-safe metadata (no rid/checksum leak).
  Six deliverables + per-file `audit.md` + `text-layer-validation.json` + `checksums.sha256` under
  `deliverables/report-audit/`. Commits `11f4d07`, `cdc6d1b`, `a92af67`.
- **English PDF (`client-monthly-en-document.pdf`): delivered** — real English LTR A4-portrait doc
  (Inter, multi-page tables w/ repeated headers, appendix). Body+tables searchable.
- **Open (do NOT block campaigns; PDF ≠ Production-Ready until closed):**
  - [ ] English bold-heading text-layer extraction (Chromium Latin-subset quirk; raw-output, not ours).
  - [ ] Firefox PDF.js verification (no runtime on host).
  - [ ] Adobe Acrobat verification (not installed on host).

## Campaign Command Center — CMC-1 ✅ (2026-07-26)

Route `/projects/{project}/campaigns/{campaign}` · isolation keys
`['projects', projectId, 'campaigns', campaignId, section, filters]`.

- **Backend:** `stage` / `performance_label` / `priority` real columns (migration
  `add_internal_classification_to_unified_campaigns`) + enums (`CampaignStage`,
  `CampaignPerformanceLabel`, `CampaignPriority`). Controller: validation + index filters
  (`stage`, `performance_label`, `priority`, `needs_attention`) + audited on update. Resource exposes
  classification + `client_display_name` + `needs_attention`.
- **Frontend:** command-center header (client/internal names, status + stage + performance + priority
  badges, Demo + Needs-Attention badges, facts grid, inline classification editors = real PATCH),
  10-tab nav persisted in `?tab=`. Un-backed tabs show an honest "section building" state (no fake
  data), wired in CMC-4…14. Test-env `ResizeObserver` polyfill added.
- **Gates:** backend **147 passed**, pint+phpstan clean; frontend **18 passed**, tsc/lint/build clean.
- **Follow-up:** campaigns LIST page still hard-codes Arabic in several spots (only tested strings
  localized here) — full `t()` localization for RTL/LTR parity pending.

### CMC batches remaining
CMC-2 (KPIs + exec summary) · CMC-4 (per-campaign APIs) · CMC-5…14 (Overview timeline, Performance,
Platforms, Creatives, Budget, Funnel, Notes & Recommendations, Alerts, Reports, Activity).

## Campaign Command Center — COMPLETE ✅ (2026-07-27)

All 10 tabs live on real, project+campaign-scoped APIs (fail-closed 404 cross-project), keyed
`['projects', projectId, 'campaigns', campaignId, section, filters]`, with Loading/Empty/Error
states and no placeholders/static data:

| Tab | Backend | Batch |
|---|---|---|
| Overview + Timeline | summary + activity (audit log) | CMC-2/5 |
| Performance | performance (timeseries) | CMC-6 |
| Platforms | platforms (per-provider) + linked externals | CMC-7 |
| Creatives | creatives (external_creatives + creative_daily_metrics), ranked by objective | CMC-8 |
| Budget | budget pacing + change history | CMC-9 |
| Funnel | funnel (objective-aware) | CMC-10 |
| Notes & Recommendations | campaign_annotations (Draft→Approved workflow, reports.approve gate) | CMC-11 |
| Alerts | app_notifications filtered to campaign entity | CMC-12 |
| Reports | reports.campaign_id link + real export tokens | CMC-13 |
| Activity | audit-log timeline | CMC-14 |

- Per-campaign metrics reuse `MetricsAggregator::forCampaign()` (identical KPI derivation as
  project analytics). Backend `CampaignMetricsTest` = 8 isolation/workflow/ranking tests.
- Creatives ranked by campaign OBJECTIVE with explainable reason + classification; thumbnails
  NEVER fabricated (UI shows "preview unavailable"). `DemoCreativesSeeder` deterministic, is_demo.
- `migrate:fresh --seed` now yields a complete demo (18 campaigns / 5 reports / 60 creatives /
  10.8k metrics) via `DatabaseSeeder` → Demo/Analytics/Reports/Creatives seeders.

Gates: backend **156 tests** + pint/phpstan clean + migrate:fresh --seed clean; frontend
tsc/lint/**vitest 18**/build; e2e report-pdf-download + campaigns + campaigns-linking green;
CMC-2/6/8/9/11 live-verified with screenshots.

### Next (per directive, autonomous)
Merge feat/premium-ui → feat/alerts → complete Alerts → **Objective-Based Report Engine**
(ReportObjectiveController / ReportTemplateResolver / per-objective + per-platform strategies) →
Connectors + Creative Sync (real data, "Awaiting Credentials" where no keys) → Scheduled Reports
→ Tasks → Connection Center → Jobs/Horizon → MCP → PWA → Production.

---

## Governance layer added (2026-07-27) ✅
Central review system now enforced per the two-review mandate:
- ✅ `docs/MASTER_REQUIREMENTS.md` — every mandated requirement (R1–R8) with canonical status + evidence.
- ✅ `docs/IMPLEMENTATION_MATRIX.md` — BE ↔ FE ↔ Test coverage per capability.
- ✅ `docs/OPEN_GAPS.md` — nothing dismissed as "transient"; G-001..G-005 tracked.
- ✅ `docs/REGRESSION_CHECKLIST.md` — run after every phase; run log started.

### Flake diagnosis (retires the earlier "transient" claim)
- Isolated `CampaignDetailPage.test.tsx` ×5 → 5/5 pass.
- Full `vitest run` ×8 → 8/8 pass (18/18 each). Total **13/13 clean**.
- Original failing assertion was not captured → cause **not** declared closed; logged as **G-001 (Watch)**
  with leading hypothesis (unmocked CMC query hooks under parallel workers) and a concrete close-out action.
- `e2e/auth-forms.spec.ts` → 7/7 pass (fields ≥50px, ≥16px, not pill, no overflow, RTL, mobile).

_Auth Phase 1 + form system stand green. Next: G-005 post-login redirect, then registration journey._

---

## Auth phase-1 — cross-browser acceptance CLOSED (2026-07-27)
**Correction to the running tally:** this directive sequence has produced **5 commits** to date
(`454b678`, `0313355`, `1f5e118`, `8703531`, `86d3b7f`) — the earlier "7 commits" summary was wrong;
`eadce97` predates this sequence. Do not carry that miscount forward.

Cross-browser + acceptance work this batch:
- Added Firefox + WebKit Playwright projects (`playwright.config.ts`); installed both engines.
- `auth-forms.spec.ts`: mobile now 320/375/390; added no-InfluencerHub-branding checks on all 3 auth pages.
- New `auth-visual.spec.ts`: visual-regression baselines (/login /register /forgot-password × light+dark,
  chromium), keyboard-only nav, console-error guard (guest `/auth/me` 401 allow-listed).
- **Results:** auth e2e **39/39** across Chromium + Firefox + WebKit; visual+kbd **10/10** chromium.
- Verified demo card never renders in production (`import.meta.env.DEV` gate); logged its dead-code
  presence honestly as G-008 (Low).

**Browsers tested:** Chromium, Firefox, WebKit. **Live review:** /login light+dark, desktop+mobile.
**Remaining:** non-auth-page WCAG contrast audit; strip demo dead-code (optional, G-008).

---

## Phase 2 batch — User profile / password / security (2026-07-27)
- **المرحلة / Phase:** User Profile Core → Password → Sessions → Unified User Menu
- **Commits:** `f1a69f6` (backend /api/me), `d3eca8a` (frontend menu+settings), `32eca48` (account e2e + docs)
- **Status:** NOT fully Completed — operational core done; open items tracked as G-009/G-010/G-011.
- **Tests run:** backend `php artisan test` 165/165 · pint + phpstan clean · frontend typecheck/lint clean ·
  vitest 22/22 · build ✓ · e2e `account-settings.spec.ts` 4/4 (chromium)
- **Browsers tested:** Chromium (account e2e). Firefox/WebKit for account flow: not yet run.
- **Live review:** logged in, /settings/profile — changed display name → immediate update in topbar avatar
  (DU) + sidebar card + persisted after reload; unified menu header shows full email + role (Tenant Owner) +
  workspace (Demo Agency) + status; no console errors.
- **المتبقي / Remaining:** /settings/notifications + /settings/preferences pages; avatar upload; workspace-
  settings entitlement gate; account e2e on Firefox/WebKit.
- **العوائق / Blockers:** multi-session enumeration + 2FA need infra (G-009); mail stays Awaiting Credentials.
- **Regressions:** none (backend 165/165, frontend 22/22).
- **المرحلة التالية / Next:** Public Homepage (`/`) — the primary conversion surface.

## Commit ledger — auth+account directive sequence (verified against `git log eadce97..HEAD`)
1. `454b678` feat(auth): safe post-login redirect + central governance docs
2. `0313355` fix(auth): env-driven login throttle + permanent redirect E2E (closes G-007)
3. `1f5e118` fix(auth): unify login visual identity and responsive experience
4. `8703531` docs: record auth-visual completion + queue user profile/settings phase
5. `86d3b7f` fix(auth): unify login identity and paid-advertising messaging
6. `8f89be1` test(auth): cross-browser + visual-regression acceptance for phase-1
7. `f1a69f6` feat(account): real /api/me profile, password and session endpoints
8. `d3eca8a` feat(account): unified user menu + operational profile/password/security settings
9. `32eca48` test(account): e2e for profile display-name journey + governance docs

(9 commits. `eadce97` — the form-system commit — predates this sequence and is NOT counted.)

## Phase 3 batch — Public homepage (2026-07-27)
- **المرحلة / Phase:** Public Homepage (`/`) — primary conversion surface
- **Commit:** `3420b9a` feat(marketing): build complete CampaignsHub public homepage; docs `812e9f9`
- **Tests run:** homepage e2e **9/9** across Chromium+Firefox+WebKit (incl. mobile 375, preview tab switch,
  language toggle, CTA→/requests/new) · auth/account regression e2e **17/17** · vitest **22/22** ·
  typecheck/lint/build green
- **Browsers tested:** Chromium, Firefox, WebKit
- **Live review:** `/` renders full RTL homepage (Emerald-on-Graphite), official terminology, interactive
  7-tab product preview tagged "demo data", journeys, honest integration statuses; 16 CTAs → real routes;
  authed header shows "back to dashboard"; `/requests/new` = Route Available — Workflow Not Implemented (honest stub); no horizontal overflow; no console errors
- **المتبقي / Remaining:** external request portal (G-012), homepage visual-regression baseline DONE (G-013, home-light/dark baselines, 7/7 clean)
- **العوائق / Blockers:** none new (integration keys stay Awaiting Credentials — shown honestly)
- **Regressions:** none (app moved to /dashboard; all prior auth/account/campaign routes intact; 17/17 e2e)
- **المرحلة التالية / Next:** External Request Portal → Request Tracking → Requests Dashboard → Clients

## Phase 4 batch — External Request Portal (backend foundation) (2026-07-27)
- **المرحلة / Phase:** External Request domain + secure intake + tracking (backend)
- **Commit:** `16d40f2` feat(requests): add external request domain and secure intake
- **Tests run:** backend `php artisan test` **170/170** (+5 RequestIntakeTest) · pint + phpstan clean · live HTTP
- **Live HTTP verified:** POST /api/v1/requests → 201 (REQ-2026-XXXXXX, 48-char token, email_delivery=awaiting_credentials);
  GET /requests/track/{token} → client-safe view, **no tenant_id / internal fields**, 410 on revoked
- **المتبقي / Remaining:** dynamic multi-step form UI, attachments, confirmation page, tracking UI, internal
  dashboard (Kanban/Table/Cards + SLA + assignment + comments), transactional conversion, clients + command center
- **العوائق / Blockers:** mail stays Awaiting Credentials (intake still works, token issued)
- **Regressions:** none (170/170)
- **المرحلة التالية / Next:** dynamic intake form UI + tracking UI, then internal requests dashboard

## Visual refinement batch — homepage + login (2026-07-27)
- **Commits:** `4756b17` fix(marketing): rebalance homepage · `7c54bc7` fix(auth): restructure login
- **Tests run:** auth+homepage e2e across Chromium+Firefox+WebKit (50 pass, 4 visual skips off-chromium) ·
  account + auth-visual chromium 11/11 · vitest 22/22 · typecheck/build green · fresh visual baselines (8)
- **Live review:** homepage 1440 — dominant H1 "كل حملاتك الإعلانية المدفوعة في مكان واحد", equal-height
  columns (706==706), large interactive preview, journey card with 68px options, workflow strip; mobile 375
  value-first, no overflow. Login — wider marketing panel, big title, 4 described features, 56px fields, demo
  card below form; light+dark baselines clean.
- **Remaining:** none for this refinement. **Next:** resume External Request Portal (dynamic form UI + tracking UI).
- **Regressions:** none (request backend untouched — 16d40f2 intact).

## Correction — precise test-status vocabulary (2026-07-27)
The "14 passed" figure was the TEST count (behavioral + visual) for that run, not baselines. The actual
visual baselines are **8 PNG files** (chromium-darwin), regenerated for the new design:
- auth-visual: login-{light,dark}, register-{light,dark}, forgot-password-{light,dark} (6)
- homepage: home-{light,dark} (2)

Honest per-suite status (do not say "cross-browser ✓" for the visual gates):
- **Functional E2E: Chromium + Firefox + WebKit — Passed**
- **Visual Regression: Chromium — Passed**
- **Firefox/WebKit Visual Regression — Not Executed** (chromium-only baselines by design)
- **Request Backend — Implemented and Tested** (commit 16d40f2)
- **Request Frontend — In Progress** (/requests/new + /requests/track UIs are still stubs)

## Phase 4b — Dynamic external intake form (2026-07-27)
- **Commit:** `f99a1ca` feat(requests): build dynamic external intake experience
- **Tests run:** request-intake e2e **9/9** across Chromium+Firefox+WebKit (homepage→form→validation→submit→
  REQ number; draft persists on reload) · RequestIntakeTest **6** (incl. meta) · backend pint/phpstan clean ·
  frontend typecheck/lint/build green
- **Live review:** /requests/new renders 5-step Stepper with 11 real service types from /requests/meta;
  per-service fields; submit returns real REQ-2026-XXXXXX + tracking link + "Awaiting mail credentials"
- **Honest status:** Functional E2E Chromium+Firefox+WebKit — Passed; secure FILE UPLOAD + real tracking UI +
  internal dashboard + conversion are the remaining request commits
- **Regressions:** none
- **Next:** `feat(requests): add secure files tracking and client communication` (file upload + /requests/track UI)

## Phase 4c — Draft PII fix + secure temporary uploads (2026-07-27)
- **Commits:** `57af700` fix(requests): store only non-sensitive draft (no PII) · `546a6bc` feat(requests):
  add secure temporary uploads and file isolation
- **Security fix (blocker):** intake draft no longer stores any PII in localStorage — only {type, step, ts}
  with 24h expiry + Clear-draft button; e2e asserts localStorage has no PII (only [step, ts, type]).
- **Secure uploads:** expiring session → private-disk UUID storage (path never exposed) → MIME allowlist
  (detected type) + 10MB + per-session cap → associate-on-submit + session retired → hourly prune of
  orphans. Malware scan config-gated OFF (not claimed).
- **Tests run:** RequestUploadTest **6** + request-intake e2e 8/8 (chromium) · backend **177/177** · pint/phpstan clean
- **Honest status:** Request Backend (intake+meta+uploads) — Implemented and Tested; **Request Frontend
  (attachments UI + real tracking UI + internal dashboard) — In Progress**; External Request Portal — In Progress
- **Next:** wire attachments step to upload endpoints; build real /requests/track UI + client reply; then /app/requests

## Phase 4d — Attachments UI + secure tracking + client communication (2026-07-27)
- **Commits:** `7d18064` connect secure uploads to dynamic intake · `e80d15a` add secure tracking and client communication
- **Tests run:** backend **180/180** · request-intake e2e **15/15** across Chromium+Firefox+WebKit · pint/phpstan clean
- **Delivered (EXTERNAL side complete):** 6-step form with attachments UI (progress/retry/remove, block-Next
  while uploading, token in memory only) → submit associates files → success page → real /requests/track UI
  (status/timeline/comments/files + secure token download) → client reply (always client-visible; can't create
  internal notes) → env-driven intake throttle (prod strict, local relaxed).
- **Honest status:** External request experience — Implemented and Tested (3 browsers). **Internal side
  (/app/requests dashboard + assignment + status state-machine + SLA + internal notes + notifications) — Not
  Started.** The full external→internal vertical E2E (owner sees internal note the client cannot) awaits the dashboard.
- **Next:** `feat(requests): add internal request dashboard and detail workflow`, then `... assignments state machine sla and notifications`.

## Phase 4e — Internal requests dashboard + FULL VERTICAL FLOW (2026-07-27)
- **Commits:** `b23274f` internal dashboard API/state-machine/SLA/notifications · `5c89d9d` dashboard+detail UI + vertical E2E
- **Tests run:** backend **183/183** (RequestDashboardTest full vertical + isolation + permission) · pint/phpstan clean ·
  request-vertical e2e **6/6** across Chromium+Firefox+WebKit · broad e2e regression 17/17 · vitest 22/22 · build green
- **Vertical flow PROVEN (the acceptance path):** guest submits (with file) → owner logs in → request appears in
  /app/requests → open detail → assign to me → change status (state-machine; illegal jumps 422) → request info
  (→ waiting_client + SLA pause) → internal note (owner sees it) → public tracking link does NOT show the internal
  note → client reply resumes SLA → in-app notification created. Live-verified dashboard.
- **Status:** Dynamic Intake / Secure Uploads / Secure Tracking / Client Reply / Internal Dashboard / Assignments /
  Status State Machine / SLA / Internal Notes / In-App Notifications — all Implemented and Tested.
  **Transactional Conversion — Not Started (next).** Kanban/Cards dashboard views — pending (Table done).
- **Next:** `feat(requests): transactional conversion → client/project/campaign`, then Clients classification + Client Command Center.
