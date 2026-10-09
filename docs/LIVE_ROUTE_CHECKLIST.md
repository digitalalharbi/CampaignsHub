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
| `/agency/portfolio` | Portfolio — which projects/clients need attention? | REVIEWED | **1366×768, ar, dark.** Overflow 0; page 1,710 px. First screen: h1, scope and health badges, period, freshness, four KPIs (projects 5, needing attention 4, campaigns 27, spend 116,819 SAR), spend trend with its breakdown, contribution by project, the platform-health header at the fold. The follow-up queue sits at 872 px and the projects list at 1,210 px. An overview, exempt from feature-first by the standing rule, and its first screen already carries context, scope, period, freshness, KPIs and two visuals; nothing changed here. **KPI density (B1):** hero cards 174 → 132 px via `PageIntro` on `StatGrid`; trend header 388 → 346, health 624 → 582, queue 872 → 830; page 1,710 → 1,668. **768×1024 / en:** the sweep measured a 95-px sideways scroll from the projects list (six non-wrapping cells, longer English labels); the row wraps at tablet widths now — re-run on the gate's seed: 768×1024 × ar/en × dark/light all pass. Other viewports/themes: `first-viewport-sweep.spec.ts` (136 of 144 passed on its first run; the 8 that did not were this overflow and a mis-pathed route, both corrected). |
| `/agency/projects` | Projects | REVIEWED | **1366×768, ar, dark.** Overflow 0; page 1,080 px. First screen: h1, the health badge, freshness, four KPIs (total 5, active 5, paused 0, onboarding 0 — 132 px after the B1 change), the search and status filters, and the first row of project cards (name, client, accounts/team/campaigns counts, the attention line, actions). Project cards are 277 px tall — two per row — which is the one density item left on this route (§8); not changed here because the card is the project's own summary and shrinking it means choosing which of its facts leave the card, a product decision recorded rather than taken. |
| `/agency/clients`, `/agency/clients/:clientId` | Client overview | REVIEWED | **1366×768, ar, dark, 6 clients.** Overflow 0; page 951 px. First screen: intro 212 px (h1 + 4 KPIs on StatGrid 132 px), filters 106 px (search, status, currency, more), client cards from ~420 px with per-client spend/campaigns/platform chips. Nothing moved: the cards are the feature and sit on the first screen. |
| `/agency/campaigns` | Campaigns — which are active and performing? | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 2,247 px. First screen: eyebrow, h1, period, freshness, search and the platform/objective/period filters, four KPIs (active 14, needing attention 4, spend 64.5K SAR, results 782), the secondary strip (budget, spent vs budget, cost per result, ROAS, paused), the five view tabs, the priority bands, budget pacing, and the spend-vs-revenue and spend-distribution chart headings at the fold. Without a project the page states «choose a project» rather than drawing an empty estate. **KPI density (B1):** hero cards 174 → 132 px; the priority bands 565 → 523 px. Other viewports/themes: `first-viewport-sweep.spec.ts`. |
| `/agency/campaigns/:projectId/:campaignId` | Campaign detail | IMPROVED | 1366×768 ar/dark, Google Search — Brand: related-entities index moved below the tab panels (`3acf89be`). Before: related panel 273 px before the tabs, performance heading at 768 px. After: tablist 393 px, performance 479 px, related 1,634 px; overflow 0; page 2,008 px. Guard: DOM order in `CampaignDetailPage.test.tsx`. |

## B3 — analytics and content

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/analytics` | Analytics — why did performance change? | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 3,399 px. First screen: eyebrow, h1, period, freshness, the filter block (project, period, eight platform chips, campaign, outcome, objective, more — 221 px tall), the tabs, then «أين الضعف» (diagnostic, healthy) and «أين يقع الإنفاق» (platform distribution) at the fold. The evidence KPIs sit at 1,338 px under «الأرقام التي بُني عليها ما سبق» — explanation before evidence is the row's own recorded order (`ANALYTICS-DIAGNOSTIC-INTELLIGENCE-001`'s guard), so it stays. The one friction is the filter block's height, a shared `FilterBar` density topic for B1; nothing changed on this route. |
| `/agency/content`, `/agency/content/groups` | Content — which creatives produce attributable outcomes? | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 4,351 px (24 cards). First screen: eyebrow, h1, period, freshness, «about the data», the performance summary (count, spend 335K SAR, impressions 6.35M, CTR 1.91%, CPA 68.08 SAR, format-mix bar), the filter block (search, client, project, provider chips, campaign, objective, kind, health, more, sort — 241 px tall), the sort note, and the first card row starting at 691 px. Answer-first already (summary before the grid); media-first cards with the honest absence reason where no preview was fetched. The filter block's height is the same friction as Analytics — one shared `FilterBar` density change (B1), not a per-route edit. |
| `/agency/content/:creativeId` | Content detail | IMPROVED | 1366×768 ar/dark, creative without media (`ee06e224`): stage min-h-20 when empty, absence note padding p-3, objective figures moved above identity. Before: first section 609 px, figures 907 px, media frame 256 px, page 3,081 px. After: figures 469 px (eight KPIs on the first screen), frame 117 px, page 2,941 px; overflow 0. Guards: stage sizing by state + heading order (`CreativeDetailPage.test.tsx`, 22/22). Not yet measured: a creative WITH media (gate seed has stills) — covered by the sweep's content route at the list level only. |
| `/app/recommendations` | Recommendations (advertiser portal — the registry carries it under `/app`, not `/agency`; `/agency/recommendations` answers the not-found page) | NOT_REVIEWED | |

## B4 — client report surfaces

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/reports` | Reports — what happened for the client? (builder, list, sections) | REVIEWED | **1366×768, ar, dark, demo store project.** Overflow 0; page 1,339 px. First screen: h1 with the two primary actions (new live link, report builder), four KPI cards (total 5, processing 1, failed 1, sent 0), the documents/schedules tabs, the filter row (search, status, type) and the first table rows. An operational list, answer-first; nothing changed here. **KPI density (B1):** the hero grid is `StatGrid` now — cards 174 → 132 px. |
| `/reports/share/:token` | Shared live/snapshot report (client) | REVIEWED | **1366×768, ar, dark, demo live token (dashboard mode).** Overflow 0; page 4,086 px. First screen: client name/by line, download menu, report title + form label (115 px), four mode tabs (156), range 7/30/90 + four platform toggles + computed-at + refresh (227–236), four primary KPIs with sparks (292, 174 px), six secondary KPIs (478), scope counts (561), time-series + spend-distribution charts from 625 px. Context, period, scope, freshness, filters, KPIs and one visual all above the fold. Nothing moved. Snapshot/summary forms: measured in `report-live-two-forms.spec` sweep (ch-rmfmt) at 375/1440. |
| `/reports/print/:token` | Print / PDF document | NOT_REVIEWED | |
| `/r/:token` | Short share path | NOT_REVIEWED | |
| `/portal/reports`, `/portal/clients/:clientSlug/reports` | Client portal reports | NOT_REVIEWED | |

## B5 — email dashboard and alerts

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/admin/email` (platform admin — `EmailOperationsPage`; the registry carries no `/agency/email`) | Email operations | NOT_REVIEWED | Agency-side email schedules are reached from Reports (schedule controls on the report), not from a route of their own. Admin role needed; the preview's agency account cannot open it. |
| `/agency/alerts` | Alerts | REVIEWED | **1366×768, ar, dark — empty state.** Overflow 0; page 768 px. First screen: h1, the alerts/rules/preferences/log tabs, four summary KPIs (open 0, critical 0, snoozed 0, resolved 0, each with its own empty sentence rather than a bare zero), the filter row (search, status, project, severity) and the ledger's honest empty state «لا تنبيهات مفتوحة». Nothing changed on this route; data-rich state not reachable on the preview estate (no rule fired), which the sweep records as an empty-state review. **KPI density (B1):** hero row converted with the others — 174 → 132 px. |
| `/agency/account/notifications` (the registry redirects `/agency/notifications` here) | Personal notifications | REVIEWED | **1366×768, ar, dark.** Overflow 0; page 4,396 px (eleven preference groups). First screen: h1, master toggles (in-app/email), the Performance group's rows. A long preference list by nature; nothing moved. |

## B7 — remaining operational surfaces

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/integrations`, `/agency/integrations/review`, `/agency/connections` | Integrations — are sources connected and fresh? | REVIEWED | **1366×768, ar, dark.** Overflow 0; page 1,181 px. First screen: h1 with the connect action, three KPIs (sources 4, needing re-auth 0, accounts 2), and all four provider rows — each with its accounts, auth state, sync state («لم تُجرَ مزامنة» / last sync time) and actions — with the «all discovered accounts» toggle at the fold. It answers «connected and fresh?» at once; nothing changed on this route. **KPI density (B1):** converted with the other hero rows (`PageIntro` on `StatGrid`) — 174 → 132 px. |
| `/agency/projects/:projectId/integrations` | Project integrations | NOT_REVIEWED | |
| `/app/spend-limits` | Budgets / spend limits — within intended pacing? (advertiser portal; the sweep's first run asked `/agency/spend-limits`, which does not exist) | REVIEWED | Swept under the advertiser session at 1366×768 × ar/en × dark/light: heading in the first viewport, overflow 0 (4/4 on the gate). Composition not yet reviewed by hand. |
| `/agency/tasks` | Tasks / operations | REVIEWED | **1366×768, ar, dark, 1 task.** Overflow 0; page 768 px — fits the viewport. Intro + 4 KPIs, filters (search/status/priority/assignee), task row, status-mix bar. Nothing moved. |
| `/agency/short-links` | Short links | REVIEWED | **1366×768, ar, dark, empty.** Overflow 0; page 768 px — fits. Intro, kind toggle (WhatsApp / link), dial code + phone, submit, empty list state. Nothing moved. |
| `/agency/settings/branding` (the registry redirects `/agency/branding` here) | Branding center | REVIEWED | **1366×768, ar, dark.** Overflow 0; page 1,631 px. First screen: settings sub-nav, h1, identity-slug field, two logo slots (primary horizontal, report logo) with dark/light tiles and format notes; square icon + favicon at 758. Nothing moved. |
| `/agency/team`, `/agency/settings/permissions` (no `/agency/permissions` in the registry), `/agency/projects/:projectId/team` | Team / RBAC | IMPROVED | **1366×768, ar, dark.** `/agency/settings/permissions`: overflow 0; page 1,172 px; invite form + member table on the first screen. `/agency/team`: overflow 0; page 1,260 px; intro + 3 KPIs (212 px), then four member cards of 165–213 px each (815 px for four members, 2.5 visible). IMPROVED (`496bd3d7`): member card is identity | access from `sm` up — cards 165–213 → 114–142 px, list 815 → 552 px, three full cards on the first screen; 390×844 stacks, overflow 0. Cross-cutting (`PageIntro`, every intro page): KPI row two columns at phone width, 528 → 260 px. `/agency/projects/:projectId/team`: not yet opened. |
| `/agency/settings/workspace`, `/agency/account/{profile,password,security,preferences}` (billing and audit are `/admin/billing`, `/admin/audit`) | Settings, account | REVIEWED | **1366×768, ar, dark.** `/agency/settings/workspace`: overflow 0; page 1,064 px; general settings form (name, type, site, phone, country, language, currency, timezone, date format) on the first screen. `/agency/account/*`: not yet opened. |
| `/agency/requests`, `/agency/leads`, `/agency/deliverables`, `/agency/files`, `/agency/drive` | Requests, leads, deliverables, files | NOT_REVIEWED | |

## Portals and admin

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/app/*` | Advertiser portal (same route family as `/agency`) | NOT_REVIEWED | |
| `/portal/*` | Client portal (campaigns, requests, quotes, invoices, messages, files, profile, spaces) | NOT_REVIEWED | |
| `/admin/*` | Platform admin (tenants, registrations, taxonomies, system, cutover, public-pages, settings) | NOT_REVIEWED | |
| `/`, `/services`, `/services/:category`, `/welcome`, auth routes | Public / auth | NOT_REVIEWED | |

Out of scope by §59: nothing here adds a product. Routes not in the registry are not invented.
