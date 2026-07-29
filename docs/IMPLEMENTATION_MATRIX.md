# CampaignsHub — Implementation Matrix

> **HANDOFF SNAPSHOT — 2026-07-29 (context-window emergency close).**
> Branch `feat/taxonomy-ux` · HEAD `fc90666` · working tree CLEAN · no WIP.
> Backend 422 passed (2467 assertions) · Frontend 215 passed · tsc/build clean ·
> Playwright **Chromium only** 144/0 (Firefox + WebKit still to run).
> Full state, next task and acceptance criteria: **`docs/RESUME_STATE.md`**.

> **The authoritative per-requirement matrix is `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`** — this file is
> a summary view and must never contradict it.
>
> Status counts at handoff: **11 VERIFIED · 22 IMPLEMENTED_NOT_VERIFIED · 6 PARTIAL · 8 NOT_STARTED ·
> 6 BLOCKED_EXTERNAL_CREDENTIALS · 1 BLOCKED_NO_API.**
>
> `IMPLEMENTED_NOT_VERIFIED` means the code exists, is tested and was reviewed live **on Chromium only**.
> Those rows may be flipped to `VERIFIED` **only after** `XBROWSER-GATE` (Firefox + WebKit) passes.
>
> | ID | Status | Evidence |
> |---|---|---|
> | `SITE-CMS-001` | IMPLEMENTED_NOT_VERIFIED | `f0c813e` + `320b569`; 6 tests/33 assertions; live publish→homepage change with no code edit |
> | `SITE-CMS-002` | **NOT_STARTED — EXACT NEXT** | API + editor ready; portal public surfaces not wired |
> | `XBROWSER-GATE` | NOT_STARTED | Chromium 144/0 only |


Backend ↔ Frontend ↔ Test coverage per capability. Fills the gap between "a controller exists" and "the button
works and is proven". Columns: **BE** (endpoint/service), **FE** (page/component), **Test** (automated proof), **Status**.

Status vocabulary matches MASTER_REQUIREMENTS.md.

| Capability | BE | FE | Test | Status |
|-----------|----|----|------|--------|
| Login | `POST /auth/login` (AuthController) | `LoginPage` + `AuthShell` | `auth.setup.ts`, `auth-forms.spec.ts` | Implemented and Tested |
| Register | `POST /auth/register` | `RegisterPage` | `auth.setup.ts` | Implemented and Tested |
| Forgot password | `POST /auth/forgot-password` (throttle 6,1) | `ForgotPasswordPage` | manual + form e2e | Implemented but Untested (email Awaiting Credentials) |
| Verify email | — | — | — | Not Started |
| Account type / onboarding | — | `RadioCardGroup` ready | — | Not Started |
| Protected redirect | RequireAuth guard | `RequireAuth` + `redirect.ts` | `redirect.test.ts` (4) + live | Implemented and Tested (G-005 closed) |
| Central form system | — | `components/ui/form.tsx` | `auth-forms.spec.ts` (7) | Implemented and Tested |
| Campaign Command Center | CampaignMetrics/Activity/Alerts/Reports/Annotation/Creatives controllers | `CampaignCommandCenter` + `metrics.ts` | `CampaignMetricsTest` (8) | Implemented and Tested |
| Objective report engine | `ReportTemplateEngine::objectiveTemplate` | report views | `ReportTemplateEngineTest` (7) | Implemented and Tested |
| Arabic client PDF | `ReportExporter` + ChromiumPdfRenderer + fix-arabic-textlayer.py | export button | `report-pdf-download.spec.ts` | Implemented and Tested (viewer gaps in OPEN_GAPS) |
| Real analytics data | MetricsAggregator + connectors | analytics tabs | isolation tests | Implemented and Tested |
| Alerts | CampaignAlertsController | `CampaignAlertsTab` | part of CMC tests | In Progress |
| Public homepage | — (static copy) | `PublicHomePage.tsx` + `homeCopy.ts` | Functional E2E Chromium+Firefox+WebKit; Visual Regression Chromium only | Completed (FF/WebKit visual Not Executed) |
| Homepage → real routes | router (`/`, `/dashboard`, `/requests/*`) | `router.tsx` pathless app layout | auth-redirect + homepage e2e | Completed |
| Requests intake `/requests/new` | `PublicRequestController::store`+`meta` | `RequestIntakePage.tsx` (5-step dynamic form) | `request-intake.spec.ts` 9/9 (3 browsers) + RequestIntakeTest | Implemented and Tested (files pending) |
| Requests tracking `/requests/track` | — | `RequestsPublicStub.tsx` | — | **Route Available — Workflow Not Implemented** |
| Requests domain (tables/model) | `create_requests_tables` + 7 models + RequestCatalogSeeder | — | RequestIntakeTest (5) | Completed (schema) |
| Public request intake | `PublicRequestController::store`+`meta` + `RequestIntake` | `RequestIntakePage.tsx` | RequestIntakeTest (6) + request-intake e2e | Implemented and Tested |
| Public request tracking | `PublicRequestController::track` (token, client-safe) | (tracking UI pending) | RequestIntakeTest + live HTTP | Backend Done — UI Not Implemented |
| Requests dashboard | — | — | — | Not Started |
| Clients classification | — | — | — | Not Started |
| Client command center | — | — | — | Not Started |
| Request → operation conversion | — | — | — | Not Started |
| Navigation Resolver (Personal/Company) | — | — | — | Not Started |
| Module entitlements | feature_flags (partial) | — | — | In Progress |
| Public homepage (2 CTAs) | — | — | — | Not Started |
| PWA / a11y / cross-browser | — | — | — | Not Started |

_Last updated: 2026-07-27 · branch `feat/auth-premium`_

---
## EXPANSION — three experiences (branch feat/three-experiences)

Status legend: Tested = feature tests + phpstan green; Awaiting Ext Dep = adapter/sandbox delivered, real credentials pending.

| Capability | Layer | Status | Evidence |
|---|---|---|---|
| Expansion architecture | docs | Tested (doc) | `docs/EXPANSION_ARCHITECTURE.md` |
| Billing: quotes/invoices/payments + honest verified-webhook settlement | backend | **Tested** | `BillingTest` 9; commit `daf9c8e` |
| Payment gateway (Stripe/Moyasar/Tap) | provider | Awaiting Ext Dep | `NullPaymentProvider` default; `config/billing.php` |
| Messaging: client⇄team threads + team notification | backend | **Tested** | `MessagingTest` 6; commit `12d6dbd` |
| Branding Center: assets/scopes/light-dark/sizes/white-label | backend | **Tested** | `BrandingTest` 7; commit `ee0322c` |
| Request Journey: full state machine + hierarchical taxonomy | backend | **Tested** | `RequestJourneyTest` 8; commit `fb1547d` |
| Connection Center: 16 connectors + honest 7-state framework | backend | **Tested** | `ConnectionCenterTest` 9; commit `e4dced5` |
| Real ad/analytics/store/Drive sync | connector | Awaiting Ext Dep | Sandbox verified; `config/connectors.php` |
| Backend expansion integrated + regressed | backend | **Tested** | full suite 333 passed; phpstan clean; `38c44b9` |
| Client portal backend (quotes/invoices/pay/messages/journey) | backend | **Tested** | ClientPortalBillingTest 22; commit 1815dd6 |
| Google Drive links (tenant/client/project/campaign) | backend | **Tested** | DriveTest 9; commit 00f655c; Sandbox path |
| Operations Console surface | frontend | Not Started | — |
| SaaS Workspace surface (white-label, billing, usage limits) | frontend | Not Started | — |
| Client Service Portal surface (dashboard/quotes/invoices/pay/files/messages) | frontend | **Tested** | build+37 vitest; commit e20155b; browser-verified |
| Expanded E2E (3 browsers) + expanded clean install + ZIP | delivery | Not Started | — |

## Consolidation (1251a39) + internal UIs integrated
| Item | Status | Evidence |
|---|---|---|
| Internal UIs (Billing/Finance, Messaging, Request Journey, Branding, Connections, Drive, Subscriptions) | **Tested** | build clean, 112 vitest; commits 896bae6/0ecb7ec/6de34e0 |
| Client portal Files/Campaigns/Reports (real data) | **Tested** | commit 6de34e0; no ComingSoon remains |
| Canonical consolidation (one module/name/route/engine) | **Tested** | commit 1251a39; DUPLICATION_AUDIT/CANONICAL_MODULES/NAVIGATION_MATRIX/ROUTE_REDIRECT_MAP; redirects for /integrations,/app/connections,/app/drive,/app/branding |
| Operations Console vs SaaS Workspace nav | **Tested** | AccountEntitlements PERSONAL_NAV/COMPANY_NAV; RegistrationOnboardingTest; browser-verified |
| Dev environment (scripts/dev-*.sh + /dev/status) | **Tested** | all services Running; commit 6c834dd |
| Expanded E2E (3 browsers) | In Progress | e2e/expansion-surfaces + full suite running |
| Expanded clean install + ZIP + SHA-256 | Pending | scratchpad/clean_install_rehearsal + package_expanded |

## PHASE: Taxonomy & UX (feat/taxonomy-ux) — binding tracks (close only when ALL "Implemented & Tested")
| # | Track | Status |
|---|---|---|
| 1 | Taxonomy & Option Management backend | **Implemented & Tested** (4f4a42e; 388 backend, 23 defs/123 opts) |
| 2 | Settings Taxonomy Manager UI | In Progress |
| 3 | Searchable/Creatable/Multi-select controls | **Implemented & Tested** (cebbbb8; 143 vitest) — adoption pending |
| 4 | Requests classification adoption (dashboard filters engine-fed; dependent Service→Category→Type primitive shipped; public intake stays enum by design) | **Implemented & Tested** (96d65b2) |
| 5 | Clients classification adoption (status/level/industry/priority/source engine-fed; tags + enabled_services multi-select) | **Implemented & Tested** (96d65b2) |
| 6 | Campaigns objective-based (objective→KPIs/funnel/template from metadata; 6 multi-selects → jsonb; round-trip resource) | **Implemented & Tested** (96d65b2, 3836d88) |
| 7 | Reports/Alerts/Integrations option adoption (types/audiences/severities/categories/provider-states/file-categories → engine; keep system keys) | Not Started |
| 8 | Integrations page redesign (Summary→Tabs→Search/Status→Compact Grid→Drawer; Drive under Files) | **Implemented & Tested** (2be3a2c; browser-verified) |
| 9 | Marketing homepage redesign (shorter/balanced; hero+CTA+preview first viewport) | **Implemented & Tested** (db64503; browser-verified) |
| 10 | All forms UX overhaul (wide fields/sections/stepper/validation/error-summary/searchable/create/dependent/review) | Not Started |
| 11 | Safe legacy data migration (re-alignment converged: drifted keys deactivated not deleted; tenant options untouched) | **Implemented & Tested** (5181773) |
| 12 | Permissions/Audit/Tenant isolation (taxonomies.*/options.*) | **Implemented & Tested** (backend) — UI gating pending |
| 13 | Three-app regression (Operations/SaaS/Client on one engine, scoped by permission/plan) | Not Started |
| 14 | Cross-browser/mobile/RTL-LTR/light-dark E2E | Not Started |
