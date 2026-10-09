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
| `/agency/dashboard` | Dashboard — what needs attention now? | IMPROVED | **1366×768, ar, dark.** Before: first screen = title, four count cards, client-mix bar, objective chart, the attention heading at the fold; client pace and budgets 4,018 px down behind a 3,138-px creative section; overflow 0; page 4,822 px. After: first screen = context, counts, attention (4 rows), client pace (5 clients), budgets heading; overflow 0 at 1366, 768 and 390; charts and the creative section follow. Guarded as DOM order (`AgencyDashboardPage.test.tsx`). **Truth defect found on the first screen and fixed:** five clients read «0.00× — on budget» for a window in which nothing had been measured for their budgeted campaigns (`budgetPacing` coalesced a missing row to a measured zero regardless of whether the project's window was measured at all); now the aggregator withdraws the figure when the project has no measured row, the rollup counts those campaigns as `unmeasured`, and the page reads «4 من العملاء بلا أرقام مقاسة في هذه الفترة» with a label beside each such client in the budgets table (`BudgetPacingNothingMeasuredTest`, 3; drawn-dashboard case). Light/en and 1440×900 not yet swept on this route. |
| `/agency/portfolio` | Portfolio — which projects/clients need attention? | NOT_REVIEWED | |
| `/agency/projects` | Projects | NOT_REVIEWED | |
| `/agency/clients`, `/agency/clients/:clientId` | Client overview | NOT_REVIEWED | |
| `/agency/campaigns` | Campaigns — which are active and performing? | NOT_REVIEWED | |
| `/agency/campaigns/:projectId/:campaignId` | Campaign detail | NOT_REVIEWED | |

## B3 — analytics and content

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/analytics` | Analytics — why did performance change? | NOT_REVIEWED | |
| `/agency/content`, `/agency/content/groups` | Content — which creatives produce attributable outcomes? | NOT_REVIEWED | |
| `/agency/content/:creativeId` | Content detail | NOT_REVIEWED | |
| `/agency/recommendations` | Recommendations | NOT_REVIEWED | |

## B4 — client report surfaces

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/reports` | Reports — what happened for the client? (builder, list, sections) | NOT_REVIEWED | |
| `/reports/share/:token` | Shared live/snapshot report (client) | NOT_REVIEWED | |
| `/reports/print/:token` | Print / PDF document | NOT_REVIEWED | |
| `/r/:token` | Short share path | NOT_REVIEWED | |
| `/portal/reports`, `/portal/clients/:clientSlug/reports` | Client portal reports | NOT_REVIEWED | |

## B5 — email dashboard and alerts

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/email` | Email settings / schedules | NOT_REVIEWED | |
| `/agency/alerts` | Alerts | NOT_REVIEWED | |
| `/agency/notifications` | Notifications | NOT_REVIEWED | |

## B7 — remaining operational surfaces

| Route | Surface | Status | Evidence |
|---|---|---|---|
| `/agency/integrations`, `/agency/integrations/review`, `/agency/connections` | Integrations — are sources connected and fresh? | NOT_REVIEWED | |
| `/agency/projects/:projectId/integrations` | Project integrations | NOT_REVIEWED | |
| `/agency/spend-limits` | Budgets / spend limits — within intended pacing? | NOT_REVIEWED | |
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
