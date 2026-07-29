# REQUIREMENTS TRACEABILITY MATRIX — CampaignsHub (living tracker)

> Statuses: NOT_STARTED · IN_PROGRESS · PARTIAL · IMPLEMENTED_NOT_VERIFIED · VERIFIED · BLOCKED_EXTERNAL_CREDENTIALS.
> Update AFTER tests + commit only. Never delete a row to hide non-implementation. "Verified" = code + test +
> browser review + commit. See `MASTER_EXECUTION_CONTRACT.md` for the definition of done. Expand atomic rows as
> each module is built; this is the authoritative open/closed ledger.

## Legend for evidence
Commit = short hash. Test = suite that covers it. Review = route reviewed live.

## Matrix
| ID | Module | Requirement | Backend | Frontend | Test | Status | Commit | Remaining gap |
|---|---|---|---|---|---|---|---|---|
| UNIFIED-001 | C DASH | Shared UnifiedCampaignOverview component (KPIs + 6-platform comparison + spend dist + top campaigns + needs-attention + alerts + data-status) | n/a | ✓ | 11 chromium E2E + 209 vitest | **VERIFIED** | 2a2d4b5 | — |
| UNIFIED-002 | C DASH | Dashboard leads with the shared overview (live analytics→VM), trend+funnel below | uses analytics API | ✓ | E2E dashboard/campaigns | **VERIFIED** | 2a2d4b5 | — |
| HOME-010 | A HOME | Marketing homepage preview uses the SAME UnifiedCampaignOverview (labeled demo data) — parity | n/a | ✓ | 13 chromium E2E + 15 vitest | **VERIFIED** | 34aa1e1 | (minor) preview section labels are AR-only on the EN homepage — localize later |
| HOME-012 | A HOME | Journey param VALUES self-service/multi-client (was self-managed/agency); RegisterPage preset preserved (multi-client→agency account) | ✓ | ✓ | 25 vitest + 9 chromium E2E (homepage+registration) | **VERIFIED** | cc593ef | — |
| HOME-013 | A HOME | Differentiated public experiences per portal (?portal=paid/influencer/client) — distinct hero + tailored preview | n/a | ✓ | 18 marketing vitest (3 portals) | **IMPLEMENTED_NOT_VERIFIED** | d9f04a4 | live-reviewed influencer only; still: client+paid portals live, mobile/RTL-LTR/light-dark, firefox/webkit, all CTAs |
| AUTH-002 | B AUTH | Login marketing panel adapts to portal (?portal / /client redirect): paid/influencer/client | n/a | ✓ | 13 auth vitest (3 variants) | **IMPLEMENTED_NOT_VERIFIED** | 0a1be73 | still: live review of all 3 login variants, post-login destination, safe-redirect/no-open-redirect, register/track/forgot links |
| HOME-011 | A HOME | Compact balanced marketing hero preview (marketing variant = 4 KPIs + platforms+donut side-by-side + 3 top campaigns; no long dashboard tail) | n/a | ✓ | homepage E2E chromium+firefox+webkit + 15 vitest; browser: 1440×900 + mobile 375, RTL | **VERIFIED** | code 4e0e2d0 (matrix doc 2a3a95e) | preview panel is dark-by-design (light/dark N/A for it; page chrome theme unchanged from prior VERIFIED homepage); AR-only preview labels on EN |
| HOME-001 | A HOME | v5 customer-language homepage, zero internal jargon, 65/35 hero, 4 journeys, login | n/a | ✓ | 15 vitest + homepage E2E | VERIFIED | 510918a | — |
| AUTH-001 | B AUTH | /login customer redesign (two-pane, green palette, responsive) | Sanctum | ✓ | auth e2e 51 | VERIFIED | 2382177 | — |
| DASH-010-A | C DASH | Backend filter contract — platform(provider) filter across all metrics endpoints | ✓ | n/a | MetricsTest 16 (new platform-filter test) | **VERIFIED** | 6c1d373 | extend to client/project/campaign/objective/status/ad-account/region |
| DASH-010-BC | C DASH | Frontend filter state + platform filter bar wired to all dashboard tiles (backend-supported) | uses API | ✓ | 209 vitest + 9 chromium E2E render | **VERIFIED** | 30ee70f | live click-through review pending; more dimensions + reset/saved-views/compare-period |
| DASH-010 | C DASH | Unified filter bar (period/client/project/campaign/platform/objective/status/ad-account/region) + saved views + compare-period + objective KPIs, all backend-supported | partial | partial | — | **IMPLEMENTED_NOT_VERIFIED** | — | E-frontend + F + G remain; verify full cross-browser/mobile/RTL-LTR/light-dark at G before VERIFIED |
| DASH-010-D | C DASH | Objective-specific KPIs — backend metric expansion + objective filter + dashboard KPI switching | ✓ | ✓ | MetricsTest 17 + 215 vitest + 9 E2E | **VERIFIED** | 3a29c4c | live objective-switch browser review pending |
| DASH-010-E-BE | C DASH | Saved dashboard views — real server-side persistence (table+model+CRUD API, user+tenant scoped, one default) + compare-period via summary | ✓ | — | SavedDashboardViewTest (19 assertions) | **VERIFIED** | faed0c9 | frontend saved-views UI + compare toggle = DASH-010-E-FE |
| DASH-010-E-FE | C DASH | Saved-views UI (save/apply/rename/set-default/delete) + default auto-apply; objective-integrity (default=Awareness, no blended mixed KPIs) | uses API | ✓ | 6 chromium E2E + build | **IMPLEMENTED_NOT_VERIFIED** | see log | live save/apply click-through + compare-toggle UI + F/G |
| CAMPAIGN-010 | D | View modes overview/table/cards/comparison/needs-attention | ✓ models | PARTIAL (cards/table) | — | **PARTIAL** | — | add overview/comparison/needs-attention + taxonomy chips |
| CAMPAIGN-020 | D | Multi-campaign comparison (spend/results/cpr/trend/platforms/creatives) | aggregator | — | — | **NOT_STARTED** | — | — |
| CAMPDET-010 | E | Campaign details depth (perf-over-time, ad sets, ads, creatives, audience, events, sync log, change log, provider ids/last-sync/attribution) | partial | PARTIAL | — | **PARTIAL** | — | build detail sections |
| CREATIVE-001 | G | Ad creatives/content module (grid/table/performance/comparison/needs-attention + detail) at /app/creatives | **none** | **none** | — | **NOT_STARTED** | — | build module + backend model + shared "top creatives" component |
| ALERT-001 | L | Alerts command center (categories/severity/status-workflow/filters/rich card/working actions; Alerts≠Notifications) | Alerts domain exists | ✓ (summary KPIs + search + severity+status filters + rich cards + resolve/snooze/create-task) | tsc+build; browser (search 35/35, 0 console err) | **IMPLEMENTED_NOT_VERIFIED** | b28e5cc | pending: category grouping by type; cross-browser/mobile/LTR/dark verify |
| RENAME-001 | M/N/R | Nav rename المالية→الاشتراكات, الرسائل→المحادثات (both locales, page titles aligned) | n/a | ✓ | tsc | **VERIFIED** | 5e4d78e | — |
| SUBSCRIPTION-UI-001 | R | Subscriptions page (/app/billing) to Campaigns/Integrations level: summary KPIs + filters + search + professional table/cards + detail drawer + states + related entities; keep current design/fonts | billing domain | ✓ (shared BillingTabs Quotes/Invoices/Payments + Quotes summary KPIs + search-by-number + status chips + no-match state; approval drawer already present) | tsc+7 billing vitest+build; browser (0 console err) | **IMPLEMENTED_NOT_VERIFIED** | 8301186 | pending: Invoices/Payments summary parity; cross-browser/mobile/LTR/dark verify |
| MESSAGE-001 | N | Unified contextual inbox (/app/messages) linked to client/request/project/invoice + actions | Messaging domain | ✓ (Conversations summary KPIs Total/Open/Closed/Active-7d + search-by-subject + status filter + no-match state; two-pane inbox+detail+reply already present) | tsc+build; browser (0 console err) | **IMPLEMENTED_NOT_VERIFIED** | 42023b9 | pending: contextual linkage panel (client/request/project/invoice); cross-browser/mobile/LTR/dark verify |
| PROJECT-UI-001 | — | Projects page (/projects) to reference level: summary KPIs + search + status filters + states; keep current design/fonts | projects domain | ✓ (Total/Active/Paused/Onboarding summary + search-by-name + status chips + no-match state; card grid+actions+modals unchanged) | tsc+build; browser (active→5 cards, 0 console err) | **IMPLEMENTED_NOT_VERIFIED** | eda7724 | pending: cross-browser/mobile/LTR/dark verify |
| REPORT-UI-001 | — | Reports page (/reports) to reference level: summary KPIs + search + status filters + states (already strong) | reports domain | ✓ (segmented status chips in bordered toolbar + filter-aware no-match state; summary/search/table/actions already present) | tsc+3 reports vitest+build; browser (0 console err) | **IMPLEMENTED_NOT_VERIFIED** | 7c83477 | pending: cross-browser/mobile/LTR/dark verify |
| FINANCE-001 | R | Unified finance center /app/finance (overview KPIs + quotes/invoices/payments/outstanding/budgets/ad-spend + detail) | billing domain | PARTIAL (3 lists) | subscriptions/billing tests | **PARTIAL** | — | build /app/finance overview + consolidation |
| TASK-001 | O | Tasks center /tasks (board/list/my/overdue) + summary + filters + real create/status actions | Tasks domain (index/store/update) | ✓ (List+Board views, summary Total/Open/Overdue/Done, search + status/priority filters + mine, create drawer, inline status PATCH, states; nav wired via entitlements) | tsc+build; backend nav 12/12; browser (209 real tasks, PATCH→200, dark+RTL) | **IMPLEMENTED_NOT_VERIFIED** | 216894d | pending: calendar view, alert/entity linkage; mobile/LTR cross-browser verify. NOTE legacy status 'open'/priority 'medium' handled but backend enum inconsistent |
| FILE-001 | P | Files module /app/files (all/clients/projects/campaigns/requests/reports/invoices/Drive) + search/preview/perms | Drive domain | **none (TabFiles only)** | — | **NOT_STARTED** | — | build page |
| PROJINT-001 | I | Project integrations show the 6 REAL platforms («المنصات الإعلانية المرتبطة»), not a generic Sandbox/Connection card | connectors | PARTIAL | — | **NOT_STARTED** (redesign) | — | rebuild ProjectIntegrationsPage around 6 platforms + honest states |
| INTEG-UI-001 | J | /app/integrations shows 6 ad platforms first in Ads tab; compact grid + drawer; no generic "Advertising Connector" card | ConnectionCenter | PARTIAL | — | **PARTIAL** | — | surface 6 platforms explicitly |
| INTEGRATION-META-001 | J | Meta Ads: OAuth/token/refresh/scopes/account+campaign+adset+ads+creative discovery/metrics sync/pagination/incremental/rate-limit/retry/backoff/idempotency/sync-history/manual-sync/disconnect/isolation/tests | stub (AwaitingCredentials) | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | build full pipeline vs Sandbox now; live needs creds |
| INTEGRATION-GOOGLE-001 | J | Google Ads (same checklist) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-TIKTOK-001 | J | TikTok Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-SNAPCHAT-001 | J | Snapchat Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-X-001 | J | X Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| INTEGRATION-LINKEDIN-001 | J | LinkedIn Ads (same) | stub | — | — | **BLOCKED_EXTERNAL_CREDENTIALS** | — | as above |
| SYNC-001 | J/F | Metrics sync pipeline (SyncRun + per-provider queued jobs) driving normalization end-to-end on Sandbox (no live jobs exist today) | **none** | n/a | — | **NOT_STARTED** | — | build SyncRun + jobs against SandboxAdvertisingConnector |
| NORM-001 | F | Metric normalization layer (canonical metrics + currency/tz/attribution/freshness) | ✓ NormalizedMetric/Aggregator | surfacing PARTIAL | — | **PARTIAL** | — | surface raw+source+objective-compat in UI + docs |
| XREL-001 | 21 | Cross-module related-entity links (client→project→platform→account→campaign→creative→alert→task→report→finance) | — | — | — | **NOT_STARTED** | — | add related-entity panels per page |
| DEMO-001 | V | Interlinked, math-consistent demo data (6 platforms, accounts, clients, projects, mixed objectives, strong/weak/needs-attention/no-data/sync-error, creatives, alerts, tasks, reports, quotes/invoices/payments), labeled | seeders | PARTIAL | 411 BE | **PARTIAL** | — | upgrade seeders + labeling |
| TAX-001 | S | Taxonomy engine + option manager (30 defs, no dups, manageable options) | ✓ | ✓ | BE 411 + vitest | VERIFIED | 5181773/16a9ba2 | keep manageable classifications engine-fed in new modules |
| FORMS-001 | T | Shared form UX (stepper/error-summary/draft/review) adopted across forms | n/a | ✓ | 209 vitest | VERIFIED | b2cb214 | apply to new modules as built |
| PAIDMEDIA-001 | Q/R | Paid-media catalog + selector + dynamic intake + request_services + quote/invoice | ✓ | ✓ | BE 411 + E2E | VERIFIED | bc61402 | — |
| REGRESS-001 | X | Three-app E2E Chromium/Firefox/WebKit 0/0 | — | — | 188 E2E | VERIFIED | b2d7278 | re-run at each phase end |
| DEVSTATUS-001 | X | /dev/status shows requirement-tracking board | DevStatusController | PARTIAL | — | **NOT_STARTED** (enrich) | — | add current/next requirement + counts |
| ADAUDIT-001 | J | docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md per-platform matrix | — | — | — | **NOT_STARTED** | — | write while implementing integrations (not before) |

## UNIMPLEMENTED REQUIREMENTS CHECK (run after each module)
Open (NOT_STARTED/PARTIAL/IMPLEMENTED_NOT_VERIFIED): HOME-010, DASH-010, DASH-011, CAMPAIGN-010, CAMPAIGN-020,
CAMPDET-010, CREATIVE-001, ALERT-001, MESSAGE-001, FINANCE-001, TASK-001, FILE-001, PROJINT-001, INTEG-UI-001,
SYNC-001, NORM-001, XREL-001, DEMO-001, DEVSTATUS-001, ADAUDIT-001, + 6 INTEGRATION-* (BLOCKED_EXTERNAL_CREDENTIALS).
Verified: UNIFIED-001/002, HOME-001, AUTH-001, TAX-001, FORMS-001, PAIDMEDIA-001, REGRESS-001.

## Exact Next Requirement
**DASH-010** — Dashboard command center: one unified filter bar (period/client/project/campaign/platform/
objective/status/ad-account/region) affecting all tiles + `DASH-011` objective-specific KPI switching + saved
views + compare-period + data-freshness. Backend: add filter params to the analytics API; Frontend: a filter
context feeding `UnifiedCampaignOverview` + the trend/funnel. Then CAMPAIGN-010 (view modes + taxonomy chips +
comparison), CAMPDET-010, CREATIVE-001, ALERT-001 … per the contract order. Keep preview up; one tested unit
per commit; update this matrix after each.
