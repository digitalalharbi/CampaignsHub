# Live route checklist — Track B (Owner directive 2026-10-09, §4)

A finite list of the product's REAL surfaces, from `frontend/src/app/router.tsx`, each with one of:
`NOT_REVIEWED` · `REVIEWED` · `IMPROVED` · `PRODUCTION_VERIFIED` · `BLOCKED_OPERATIONAL_EVIDENCE`.

Review is implementation, not audit (§3): open the rendered route → fix the highest-value friction →
test → render again → verify → next. Viewports (§5): 1366×768, 1440×900, 768, 390 × ar/en × dark/light.
Acceptance (§6–§8, §55): no page-level horizontal scroll; first viewport carries context, period, scope,
freshness, filters, primary KPIs and one useful visual; compact, not cramped.

Evidence per materially improved surface (§54): viewport, visible first-screen sections, overflow
true/false, primary KPI visibility, filter visibility, chart visibility, before/after issue resolved.

## B2 — operator core surfaces (`/agency`)

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/dashboard` | Dashboard — what needs attention now? | IMPROVED | **1366×768, ar, dark.** Before: first screen = title, four count cards, client-mix bar, objective chart, the attention heading at the fold; client pace and budgets 4,018 px down behind a 3,138-px creative section; overflow 0; page 4,822 px. After: first screen = context, counts, attention (4 rows), client pace (5 clients), budgets heading; overflow 0 at 1366, 768 and 390; charts and the creative section follow. Guarded as DOM order (`AgencyDashboardPage.test.tsx`). **Truth defect found on the first screen and fixed:** five clients read «0.00× — on budget» for a window in which nothing had been measured for their budgeted campaigns (`budgetPacing` coalesced a missing row to a measured zero regardless of whether the project's window was measured at all); now the aggregator withdraws the figure when the project has no measured row, the rollup counts those campaigns as `unmeasured`, and the page reads «4 من العملاء بلا أرقام مقاسة في هذه الفترة» with a label beside each such client in the budgets table (`BudgetPacingNothingMeasuredTest`, 3; drawn-dashboard case). **KPI density (B1):** the four count cards 174 → 136 px (the dashboard's own row, now `StatGrid`); attention top 343 → 301 px. Light/en and 1440×900 covered by `first-viewport-sweep.spec.ts`. |
| `/agency/portfolio` | Portfolio — which projects/clients need attention? | REVIEWED | **1366×768, ar, dark.** Overflow 0; page 1,710 px. First screen: h1, scope and health badges, period, freshness, four KPIs (projects 5, needing attention 4, campaigns 27, spend 116,819 SAR), spend trend with its breakdown, contribution by project, the platform-health header at the fold. The follow-up queue sits at 872 px and the projects list at 1,210 px. An overview, exempt from feature-first by the standing rule, and its first screen already carries context, scope, period, freshness, KPIs and two visuals; nothing changed here. **KPI density (B1):** hero cards 174 → 132 px via `PageIntro` on `StatGrid`; trend header 388 → 346, health 624 → 582, queue 872 → 830; page 1,710 → 1,668. **768×1024 / en:** the sweep measured a 95-px sideways scroll from the projects list (six non-wrapping cells, longer English labels); the row wraps at tablet widths now. Other viewports/themes: `first-viewport-sweep.spec.ts`. |
| `/agency/projects` | Projects | NOT_REVIEWED | |
| `/agency/clients`, `/agency/clients/:clientId` | Client overview | NOT_REVIEWED | |
| `/agency/campaigns` | Campaigns — which are active and performing? | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 2,247 px. First screen: eyebrow, h1, period, freshness, search and the platform/objective/period filters, four KPIs (active 14, needing attention 4, spend 64.5K SAR, results 782), the secondary strip (budget, spent vs budget, cost per result, ROAS, paused), the five view tabs, the priority bands, budget pacing, and the spend-vs-revenue and spend-distribution chart headings at the fold. Without a project the page states «choose a project» rather than drawing an empty estate. **KPI density (B1):** hero cards 174 → 132 px; the priority bands 565 → 523 px. Other viewports/themes: `first-viewport-sweep.spec.ts`. |
| `/agency/campaigns/:projectId/:campaignId` | Campaign detail | NOT_REVIEWED | |

## B3 — analytics and content

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/analytics` | Analytics — why did performance change? | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 3,399 px. First screen: eyebrow, h1, period, freshness, the filter block (project, period, eight platform chips, campaign, outcome, objective, more — 221 px tall), the tabs, then «أين الضعف» (diagnostic, healthy) and «أين يقع الإنفاق» (platform distribution) at the fold. The evidence KPIs sit at 1,338 px under «الأرقام التي بُني عليها ما سبق» — explanation before evidence is the row's own recorded order (`ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001`'s guard), so it stays. The one friction is the filter block's height, a shared `FilterBar` density topic for B1; nothing changed on this route. |
| `/agency/content`, `/agency/content/groups` | Content — which creatives produce attributable outcomes? | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 4,351 px (24 cards). First screen: eyebrow, h1, period, freshness, «about the data», the performance summary (count, spend 335K SAR, impressions 6.35M, CTR 1.91%, CPA 68.08 SAR, format-mix bar), the filter block (search, client, project, provider chips, campaign, objective, kind, health, more, sort — 241 px tall), the sort note, and the first card row starting at 691 px. Answer-first already (summary before the grid); media-first cards with the honest absence reason where no preview was fetched. The filter block's height is the same friction as Analytics — one shared `FilterBar` density change (B1), not a per-route edit. |
| `/agency/content/:creativeId` | Content detail | NOT_REVIEWED | |
| `/app/recommendations` | Recommendations (advertiser portal — the registry carries it under `/app`, not `/agency`; `/agency/recommendations` answers the not-found page) | NOT_REVIEWED | |

## B4 — client report surfaces

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/reports` | Reports — what happened for the client? (builder, list, sections) | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 1,339 px. First screen: h1 with the two primary actions (new live link, report builder), four KPI cards (total 5, processing 1, failed 1, sent 0), the documents/schedules tabs, the filter row (search, status, type) and the first table rows. An operational list, answer-first; nothing changed here. **KPI density (B1):** the hero grid is `StatGrid` now — cards 174 → 132 px. |
| `/reports/share/:token` | Shared live/snapshot report (client) | NOT_REVIEWED | |
| `/reports/print/:token` | Print / PDF document | NOT_REVIEWED | |
| `/r/:token` | Short share path | NOT_REVIEWED | |
| `/portal/reports`, `/portal/clients/:clientSlug/reports` | Client portal reports | NOT_REVIEWED | |

## B5 — email dashboard and alerts

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/email` | Email settings / schedules | NOT_REVIEWED | |
| `/agency/alerts` | Alerts | REVIEWED | **1366×768, ar, dark — empty state.** Overflow 0; page 768 px. First screen: h1, the alerts/rules/preferences/log tabs, four summary KPIs (open 0, critical 0, snoozed 0, resolved 0, each with its own empty sentence rather than a bare zero), the filter row (search, status, project, severity) and the ledger's honest empty state «لا تنبيهات مفتوحة». Nothing changed on this route; data-rich state not reachable on the preview estate (no rule fired), which the sweep records as an empty-state review. **KPI density (B1):** hero row converted with the others — 174 → 132 px. |
| `/agency/notifications` | Notifications | NOT_REVIEWED | |

## B7 — remaining operational surfaces

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/integrations`, `/agency/integrations/review`, `/agency/connections` | Integrations — are sources connected and fresh? | REVIEWED | **1366×768, ar, dark.** Overflow 0; page 1,181 px. First screen: h1 with the connect action, three KPIs (sources 4, needing re-auth 0, accounts 2), and all four provider rows — each with its accounts, auth state, sync state («لم تُجرَ مزامنة» / last sync time) and actions — with the «all discovered accounts» toggle at the fold. It answers «connected and fresh?» at once; nothing changed on this route. **KPI density (B1):** converted with the other hero rows (`PageIntro` on `StatGrid`) — 174 → 132 px. |
| `/agency/projects/:projectId/integrations` | Project integrations | NOT_REVIEWED | |
| `/app/spend-limits` | Budgets / spend limits — within intended pacing? (advertiser portal; the sweep's first run asked `/agency/spend-limits`, which does not exist) | NOT_REVIEWED | |
| `/agency/tasks` | Tasks / operations | NOT_REVIEWED | |
| `/agency/short-links` | Short links | NOT_REVIEWED | |
| `/agency/branding` | Branding center | NOT_REVIEWED | |
| `/agency/team`, `/agency/permissions`, `/agency/projects/:projectId/team` | Team / RBAC | NOT_REVIEWED | |
| `/agency/settings/*`, `/agency/billing`, `/agency/account`, `/agency/audit` | Settings, billing, audit | NOT_REVIEWED | |
| `/agency/requests`, `/agency/leads`, `/agency/deliverables`, `/agency/files`, `/agency/drive` | Requests, leads, deliverables, files | NOT_REVIEWED | |

## Portals and admin

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/app/*` | Advertiser portal (same route family as `/agency`) | NOT_REVIEWED | |
| `/portal/*` | Client portal (campaigns, requests, quotes, invoices, messages, files, profile, spaces) | NOT_REVIEWED | |
| `/admin/*` | Platform admin (tenants, registrations, taxonomies, system, cutover, public-pages, settings) | NOT_REVIEWED | |
| `/`, `/services`, `/services/:category`, `/welcome`, auth routes | Public / auth | NOT_REVIEWED | |

Out of scope by §59: nothing here adds a product. Routes not in the registry are not invented.
