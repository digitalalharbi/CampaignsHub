# CampaignsHub — Master Requirements

Single source of truth for every requirement the client has mandated. Each row carries a status from the
canonical set and links to evidence. **`Completed` is forbidden without live verification + evidence.**

Status vocabulary: `Not Started` · `In Progress` · `Implemented but Untested` · `Implemented and Tested` ·
`Blocked — External Dependency` · `Regression Found` · `Completed`

Two-review gate per phase:
- **Review 1 (pre-commit):** backend+frontend complete · every button/route works · no placeholder/static data ·
  validation+permissions+isolation · loading/empty/error · RTL/LTR · light/dark · desktop/mobile · console/network clean · no prior-feature breakage.
- **Review 2 (post-commit):** run the app as a real user — Login → Register → Forgot → Navigate → Save → Refresh →
  Logout → Login again → Mobile → Permissions → Error cases; then `php artisan test` / pint / phpstan and
  typecheck / lint / test / build / e2e / `playwright --workers=1 --retries=0 --repeat-each=3`, no hidden flakiness.

---

## R1 — Design identity & forms

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R1.1 | Original CampaignsHub palette (Emerald-on-Graphite), NOT InfluencerHub purple/blue | Completed | `docs/DECISIONS.md`, live login screenshot |
| R1.2 | Central form design system (wide 54px fields, 16px, labels above, not pill, no in-field icons, textarea counter) | Completed | `components/ui/form.tsx`, `e2e/auth-forms.spec.ts` (7 green) |
| R1.3 | Light + Dark complete, WCAG contrast | Implemented but Untested | needs explicit dark + contrast audit |
| R1.4 | 3 color directions compared internally, best adopted, documented | Completed | `docs/DECISIONS.md` |

## R2 — Authentication

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R2.1 | `/login` is a full page (not modal) | Completed | `LoginPage.tsx` + route |
| R2.2 | Split layout desktop, form is the clearest element | Completed | `AuthShell.tsx` |
| R2.3 | Show/hide password, remember me, forgot, create-account links | Completed | `LoginPage.tsx` |
| R2.4 | Demo creds dev-only, separate copyable box, never auto-filled | Completed | `LoginPage.tsx` (import.meta.env.DEV) |
| R2.5 | Protected route → redirect `/login`; after login → intended page or dashboard | Completed | `RequireAuth` + `redirect.ts` (`safeRedirect`); live-verified `/analytics` round-trip; 4 unit tests |
| R2.6 | Prevent double-submit; real loading; rate limiting; session handling | In Progress | button `loading` guards submit; backend throttle present; intended-path now done |
| R2.7 | Google login behind feature flag | Not Started | Awaiting Credentials (OAuth) |
| R2.8 | Language + theme toggle on auth | Completed | `AuthShell.tsx` |

## R3 — Registration journey

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R3.1 | `/register` provisions tenant + owner | Implemented and Tested | `auth.setup.ts`, AuthController |
| R3.2 | Verify Email step | Not Started | Awaiting Credentials (mail) |
| R3.3 | Account Type (Personal / Company) | Not Started | — |
| R3.4 | Workspace Setup | Not Started | — |
| R3.5 | Module Selection (RadioCardGroup ready) | Not Started | component exists, flow missing |
| R3.6 | First Client → First Project → Data Source | Not Started | — |
| R3.7 | Edge cases (email taken, expired link, resend, weak pw, network drop, partial, suspended, team invite) | Not Started | — |

## R4 — Workspace modes (rental readiness)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R4.1 | Personal Workspace nav (Clients/Projects/Campaigns/Analytics/Reports/Connections/Alerts/Requests/Settings) | In Progress | most modules exist; unified resolver missing |
| R4.2 | Company Tenant simplified nav (Dashboard/Campaigns/Analytics/Reports/Connections/Alerts/Settings) | Not Started | — |
| R4.3 | Navigation Resolver over account_type+enabled_modules+role+permissions+subscription_plan | Not Started | — |
| R4.4 | Module entitlements + feature flags + plan limits; single codebase, no fork | In Progress | feature_flags exist; entitlements table missing |
| R4.5 | Module Switcher (hidden when single module) | Not Started | — |

## R5 — Requests

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R5.1 | External request portal `/requests/new` (dynamic forms + attachments + confirmation) | Not Started | — |
| R5.2 | `/requests/track/{token}` | Not Started | — |
| R5.3 | Classification: category / stage / priority / service | Not Started | — |
| R5.4 | Internal dashboard: Kanban / Table / Cards + KPIs + SLA + search/filter/sort/pagination | Not Started | — |
| R5.5 | Convert request → client/project/campaign without re-entry | Not Started | — |

## R6 — Clients

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R6.1 | Client classification (type / status / service level) | Not Started | — |
| R6.2 | Client card (logo, projects, active campaigns, spend, alerts, last report, last sync, owner, status, open requests, sources) | Not Started | — |
| R6.3 | Client command center (Overview/Projects/Campaigns/Analytics/Reports/Requests/Team/Files/Activity/Settings) | Not Started | — |
| R6.4 | Isolation by org + client + project | Implemented and Tested | BelongsToTenant/Project scopes + CampaignMetricsTest |

## R7 — Public homepage

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R7.1 | Two distinct CTAs: "ابدأ إدارة حملاتك" (register) vs "أرسل طلب إدارة حملة" (service request) | Not Started | `docs/MARKETING_SITE.md` draft only |
| R7.2 | Marketing identity distinct from reference images | In Progress | palette decided |

## R8 — Carry-over (must not regress while building auth/public)

| ID | Requirement | Status | Evidence |
|----|-------------|--------|----------|
| R8.1 | Campaign Command Center (10 tabs, real APIs) | Implemented and Tested | CMC branch, CampaignMetricsTest (8) |
| R8.2 | Objective-based report engine | Implemented and Tested | ReportTemplateEngineTest (7) |
| R8.3 | Arabic client PDF fail-closed + real button E2E | Implemented and Tested | `report-pdf-download.spec.ts`; open items in OPEN_GAPS |
| R8.4 | Real API data only (no rand/hardcoded/fake thumbnails) | Implemented and Tested | connectors + Awaiting-Credentials states |
| R8.5 | Alerts + notifications | In Progress | CampaignAlertsController; mail Awaiting Credentials |
| R8.6 | Project isolation | Implemented and Tested | scopes + tests |
| R8.7 | PWA / accessibility / cross-browser / performance | Not Started | — |

---

_Last updated: 2026-07-27 · branch `feat/auth-premium`_
