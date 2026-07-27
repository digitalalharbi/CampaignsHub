# CampaignsHub — Implementation Matrix

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
