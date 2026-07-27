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
