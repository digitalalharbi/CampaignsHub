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
| Requests portal | — | — | — | Not Started |
| Requests dashboard | — | — | — | Not Started |
| Clients classification | — | — | — | Not Started |
| Client command center | — | — | — | Not Started |
| Request → operation conversion | — | — | — | Not Started |
| Navigation Resolver (Personal/Company) | — | — | — | Not Started |
| Module entitlements | feature_flags (partial) | — | — | In Progress |
| Public homepage (2 CTAs) | — | — | — | Not Started |
| PWA / a11y / cross-browser | — | — | — | Not Started |

_Last updated: 2026-07-27 · branch `feat/auth-premium`_
