# Builder E — Nov overhaul notes

Branch `feature/nov-overhaul-e`, based on `feature/nov-overhaul` (A and B merged). Not pushed.

## Task 3 — a dashboard per role (ROLE-DASHBOARD-001)

### What exists

- `GET /api/v1/me/dashboard[?view=manager|pm|finance|marketer]` — `RoleDashboardController` + `RoleDashboardBuilder`
  (`backend/app/Domains/Agency/`). Route sits beside `me/access`, middleware `portal:app,agency`. **No migration:** every
  figure is aggregated from what the product already computes (`budgetPacing` + `ClientBudgetRollup`, `byCampaign`
  objective results, `BillingReach` invoices, tasks, content approvals, alerts, provider connections).
- Which dashboard shows is the server's decision: owner → `manager`; preset slug `project-manager` → `pm`, `finance` →
  `finance`, `media-buyer` → `marketer`; a custom role follows its keys (billing/budget manage without campaigns →
  finance, otherwise marketer). Only the workspace manager may pass `?view=` (to preview the other three); anyone else's
  request is ignored.
- Reach is the intersection of the client roster (`ClientAccess::restrictQuery`), the project allow-list inside a client
  and `ProjectReach::dataIdsFor`. A confined member never reads another client; a section whose key the member lacks is
  returned as `null` (not computed), and a member without `budget.view`/`billing.view` gets no money at all.
- Frontend: `frontend/src/features/roleDashboard/` (`RoleDashboardPage`, one view per role, local primitives in
  `parts.tsx`). Charts are the shared layer (`features/analytics/charts`: `RankingBarChart`, `MetricLineChart`,
  `PlatformDonutChart`, `ProgressRing`); `RankingBarChart` gained one additive prop, `rowColor`, for status-coloured bars.
- Routes: `/agency/home` and `/app/home`; `/agency` and `/app` now redirect there.

### Rules the numbers follow

- Run-out date = remaining ÷ average spend of the last three COMPLETE days (today is left out). No spend in those days
  means no date, not «never».
- Status: green below 80% of the budget, orange from 80% up to the budget, red at or beyond it, grey when there is no
  reading. The API states the threshold (`thresholds.near`); `lib/colors` is the one place the page reads it.
- Remaining / pace are withheld by the rollup when any campaign in the roll-up is unmeasured or in another currency; the
  card then shows «—» and says how many campaigns are unmeasured.
- Marketer: results and cost per result are ONE objective's (the family with the most spend); ROAS only when that family
  is sales; best / worst only inside that family. «حملاتي» = campaigns he owns or created; with none, the page reads the
  campaigns he reaches and says so (`mine: false`).
- On plan = projected pace within 20% of the plan; off plan = farther.

### The shared colour system — `frontend/src/lib/colors/index.ts`

One colour per platform (`PLATFORM_COLOR`, theme tokens in `styles/tokens.css`, both themes) and `budgetStatus()` →
`STATUS_COLOR / STATUS_SOFT / STATUS_TEXT / STATUS_LABEL`. Platform hues were changed so no two look alike and none is a
status hue: Google purple, X slate, LinkedIn indigo, ChatGPT Ads teal (TikTok, Meta, Snapchat unchanged).
`analytics/platformColor.ts` re-exports it, so every existing `platformColor()` / `PLATFORM_COLORS` import already follows.

**Other screens that should adopt it** (they still hand-pick colours or carry their own thresholds):
budgets list (`features/funding/BudgetsPage`), `features/analytics/BudgetReading` and `ConsumptionBar`,
`features/agency/AgencyDashboardPage` and `features/overview/ClientCards` (their near/over cues), `features/budget/SpendLimitsPage`,
`features/projects/PortfolioPage`, `features/alerts` severity chips, `features/analytics/charts` `StatusMixBar` / `RatioBars`
tones, the integrations health pills, and the report print slides' platform bars.

### Deferred, and why

- **Landing after login is still `/…/dashboard`.** The server names that destination (`memberships` endpoint), the rail's
  «الرئيسية» leaf lives in `layouts/agencyNav.ts` / `AppShell` (builder D), and ~77 frontend and 10 backend test files assert
  `/app/dashboard` / `/agency/dashboard`. To make the role dashboard the landing, flip three things together: the
  destination the server returns, the Home leaf in the rails, and those assertions. The page and route are ready at `/home`.
- The first-viewport sweep (`e2e/first-viewport-sweep.spec.ts`) was not given a `/agency/home` row; the role spec measures
  overflow and fit itself.
- Firefox: the e2e spec is browser-agnostic; this run covered chromium and webkit (see the report).
