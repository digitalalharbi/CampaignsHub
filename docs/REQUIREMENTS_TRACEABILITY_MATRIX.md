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
| HOME-011 | A HOME | Compact balanced marketing hero preview (marketing variant = 4 KPIs + platforms+donut side-by-side + 3 top campaigns; no long dashboard tail) | n/a | ✓ | homepage E2E chromium+firefox+webkit + 15 vitest; browser: 1440×900 + mobile 375, RTL | **VERIFIED** | code 4e0e2d0 (matrix doc 2a3a95e) | preview panel is dark-by-design (light/dark N/A for it; page chrome theme unchanged from prior VERIFIED homepage); AR-only preview labels on EN |
| HOME-001 | A HOME | v5 customer-language homepage, zero internal jargon, 65/35 hero, 4 journeys, login | n/a | ✓ | 15 vitest + homepage E2E | VERIFIED | 510918a | — |
| AUTH-001 | B AUTH | /login customer redesign (two-pane, green palette, responsive) | Sanctum | ✓ | auth e2e 51 | VERIFIED | 2382177 | — |
| DASH-010 | C DASH | Unified filter bar (period/client/project/campaign/platform/objective/status/ad-account/region) affecting all tiles + saved views + compare-period | needs API params | — | — | **NOT_STARTED** | — | build filter context + API filters |
| DASH-011 | C DASH | Objective-specific KPI switching (awareness/traffic/leads/sales/app/engagement) | metrics support | — | — | **NOT_STARTED** | — | KPI set per objective |
| CAMPAIGN-010 | D | View modes overview/table/cards/comparison/needs-attention | ✓ models | PARTIAL (cards/table) | — | **PARTIAL** | — | add overview/comparison/needs-attention + taxonomy chips |
| CAMPAIGN-020 | D | Multi-campaign comparison (spend/results/cpr/trend/platforms/creatives) | aggregator | — | — | **NOT_STARTED** | — | — |
| CAMPDET-010 | E | Campaign details depth (perf-over-time, ad sets, ads, creatives, audience, events, sync log, change log, provider ids/last-sync/attribution) | partial | PARTIAL | — | **PARTIAL** | — | build detail sections |
| CREATIVE-001 | G | Ad creatives/content module (grid/table/performance/comparison/needs-attention + detail) at /app/creatives | **none** | **none** | — | **NOT_STARTED** | — | build module + backend model + shared "top creatives" component |
| ALERT-001 | L | Alerts command center (categories/severity/status-workflow/filters/rich card/working actions; Alerts≠Notifications) | Alerts domain exists | PARTIAL | — | **NOT_STARTED** (redesign) | — | rebuild /app/alerts |
| MESSAGE-001 | N | Unified contextual inbox (/app/messages) linked to client/request/project/invoice + actions | Messaging domain | PARTIAL (ThreadsPage) | — | **PARTIAL** | — | context panel + linkage + actions |
| FINANCE-001 | R | Unified finance center /app/finance (overview KPIs + quotes/invoices/payments/outstanding/budgets/ad-spend + detail) | billing domain | PARTIAL (3 lists) | subscriptions/billing tests | **PARTIAL** | — | build /app/finance overview + consolidation |
| TASK-001 | O | Tasks center /app/tasks (board/list/calendar/my/overdue/linked-alerts) + entity linkage | Tasks domain | **none** | — | **NOT_STARTED** | — | build page + backend wiring |
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
