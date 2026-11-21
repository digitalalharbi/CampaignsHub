# Currency engine applied to every money path: builder G notes (FX-APPLY-001)

Branch `feature/currency-engine-g`, based on `feature/currency-engine` (nov-overhaul A/B/C/E, consistency H/I/J, date
range K, engine F). Not pushed, no PR. Status: `IMPLEMENTED_NOT_VERIFIED` until the full suite, CI and a browser walk on a
live EUR account. The contract applied here is the one in `F-notes.md`. Matrix, ledger and RESUME_STATE are not edited.

## The confirmed defect, traced

«253 € on the campaign page, 253 $ in the campaigns table» came from two separate faults.

1. **A display fault (fixed).** `CampaignCommandCenter.tsx` labelled the summary totals with the campaign's BUDGET currency:
   `moneyFromTotals(k, 'spend', true, cur)`, where `cur = campaign.budget_currency || 'SAR'`. Those totals are in the
   summary's reporting currency (USD). So one figure read «€» on the page and «$» in the table. The same mislabel was on
   CPA, CPC, CPM, revenue, the trend series, the platform donut and best/worst day on the page, the funnel's costs, the
   creatives tab's spend, the events tab's cost per event, and the comparison's cost per result. They now read
   `summary.currency`, and the budget keeps its own currency.
2. **A stored-data fault (detectable, and repairable by recompute where possible).** The table printing the SAME number
   (253) with «$» means the stored `value` was 253 in USD terms. Either a row was stored without a rate (the
   `stored_without_rate` shape `fx:monitor` logs, which recompute converts again from the original), or the row's
   `original_currency` says USD on an account that is EUR. Recompute cannot fix the second case, because the label itself
   is wrong. `fx:conversion-report` counts both cases per anonymous account (`no rate stored`, `stored≠account ccy`).
   **Not repaired here.** It needs production data, and changing an original's currency label is a reviewed data repair.

## Bypass paths found and fixed

Backend: every path now converts through `FxEngine::convert()` / `->rate()`.

| Path | Before | After |
|---|---|---|
| `Funding\Support\BudgetFx` (builder H's one adapter) | `CurrencyConverter::resolveForWorkspace` | delegates to `FxEngine::convert`; adds `rate()`, `kind`, `stale`, `conversion` |
| `Metrics\Services\DisplayCurrencyAdapter` (builder I's one adapter) | `resolveForWorkspace`; display = reporting basis | delegates to `FxEngine::convert`; display currency = `FxPolicy` (via `FxDisplay`); adds `to($currency)` and `conversion` |
| `SpendLimitGovernor` (limit in another currency) | `resolveForWorkspace` | `BudgetFx::rate` → engine |
| `FundingBalance` (platform spend in USD) | `resolveForWorkspace` | `BudgetFx::rate` → engine |
| `FundingSpendRefresher` (daily spend → SAR) | `resolveForWorkspace` | `BudgetFx::rate` → engine |
| `AddFundingPayment` (ledger payment → SAR) | `CurrencyConverter::resolve` | `FxEngine::rate` |
| `Campaigns\Launch\BudgetSplit` (launch budget per account) | `resolve` | `FxEngine::rate` |
| `ProjectLinkController` (link-time currency state) | `resolve` | `FxEngine::rate` |
| `Ga4AttributionMatch`, `Ga4Classification`, `Ga4StoreAnalytics` (GA4 revenue → USD) | `resolve` | `FxEngine::rate` |
| `Billing\Services\ClientInvoiceService` (fee basis → invoice currency) | `resolve` | `FxEngine::rate` (imports fixed by pint) |
| Campaigns list / campaign page (`UnifiedCampaignResource`) | `total_budget` + `budget_currency` only | adds `budget_fx` (FxConversion to the display currency) |
| Campaign structure (ad groups) | `currency` (null for Google); the screen guessed «SAR» | adds `budget_currency` (row, else account), `daily_budget_fx`, `lifetime_budget_fx` |
| `metrics/compare` | costs had no unit; the screen used each budget's currency | adds `currency` (reporting basis), `fx`, per-campaign `budget_fx` |
| `metrics/summary` | no record of what was converted | adds `fx` = `MetricsAggregator::fxCoverage()`: converted currencies, stale rows, withheld rows by currency |
| `metrics/budget` pacing rows | budget and spend in two units, side by side | adds `budget_fx` (plan → reporting) and `spent_fx` (spend → plan currency) |
| Clients list, 30-day spend (`ClientPortfolioStats`) | `sum(value)` silently dropped withheld rows, so a smaller number was shown | adds `spend_fx`: withheld when any row is, «يشمل عملات محوّلة», converted to the display currency |
| Automation rules (`RuleEvaluator`, `RuleMetrics`) | limits compared with the reporting-basis figure; a withheld day was silently excluded from the sum | new `automation_rules.currency` (null = display currency); every money figure is converted into the rule's currency before the comparison, including `min_spend`; money with a withheld part is null with reason `no_rate_money`, never compared |
| Approval requests (`ActionProposalController`) | `display` without the conversion | adds `conversion`, `current_conversion`, `stale` |
| Report exports CSV/XLSX (`ReportExporter`) | currency named, rates not | `Exchange rates / أسعار الصرف` header row and methodology row (`ReportFxFacts`: currencies converted, rate range, dates, kind, stale, withheld), plus an `Original currency / العملة الأصلية` column on the platform table |

Frontend: all text goes through `formatConversion` / `formatFxTotal` / `withMark`.

- New `components/money/FxAmount.tsx`. `FxAmount` shows the figure with «محوّل» and the tooltip
  `253.00 EUR × 1.12 (2026-11-09، يدوي: who) = 283.36 USD`; with `showOriginal` it shows both currencies; a withheld figure
  is «—» + ⚠ + the rates link; a stale rate gets ⚠. `FxTotalAmount` adds «يشمل عملات محوّلة». `FxCoverageNote` is the
  scope note.
- New in `lib/money/fx.ts`: `conversionTooltip`, `isConverted`, `formatOriginal`, `FxTotal`, `formatFxTotal`,
  `FxCoverage`. New in `lib/money/mark.ts`: `labelUnit` (a header unit, never glued to a figure).
- F's nine listed bypasses are fixed and the list is empty: AnalyticsPage, OverviewCompositions (two sites),
  PathAnalysis, CampaignComparison ×2, CampaignStructureTab, clients/TabAnalytics, content/CreativesPage, and
  funding/labels.
- Also fixed: the CampaignCommandCenter mislabels above. The campaigns table budget cell and card use `FxAmount`. The
  campaign page budget shows both currencies. The campaigns overview shows `FxCoverageNote`. The comparison shows the plan
  via `FxAmount` and the cost in the reporting currency. Approvals (`ProposalCard`, `proposalCopy.budgetFigure`) no longer
  show «36 €» raw when there is no rate. The clients list renders `spend_fx`. Guessed defaults (`|| 'SAR'`, `?? 'SAR'`) are
  removed from CommandCenter, DepthTabs, useOverviewVm, UnifiedCampaignOverview, OverviewCompositions, StoreFunnelTab and
  FinanceOverviewPage. Hand-glued `currencyMark()` is replaced by `withMark` / `<Money>` in AlertsPage, BudgetBoard,
  BudgetDrawer, LiveSections, PortfolioPage, TabAnalytics and proposalCopy.

## Guards (strict, no silent allowlist)

- `moneyMarksGuard.test.ts`: `KNOWN_BYPASSES` is empty. Two new rules:
  - `MARKFN` refuses `currencyMark()` outside `lib/money/` and the funding formatter.
  - `FALLBACK` refuses a currency variable with a guessed default.

  Exceptions live in `NOT_A_FIGURE`, one line each with the reason (an input's unit label, form defaults), and they ratchet
  both ways. If a listed exception disappears, the test fails until it is removed from the list.
- `MoneyMarksSourceGuardTest::test_no_path_converts_money_outside_the_engine` refuses a direct rate lookup (`->resolve`,
  `resolveForWorkspace`, `rateFor` on a rates/fx/converter object) outside the named `ENGINE_HOMES`: the rule itself, the
  engine, recompute, ingest (`ReportingCurrency`, `CommerceCurrency`), the repair command and the read-only audit. Each
  home is named with its reason, and a home that no longer exists fails the test.

## Acceptance tests

- `FxApplyEveryMoneyPathTest` (registry-based) enumerates every GET route under the money prefixes from the router. Each
  route must be walked or listed in `NOT_WALKED` with a reason; an unclassified new money route fails the test. The walk
  calls 49 endpoints with a EUR world (budget 253 EUR, spend 253 EUR, ad group 253, an approval for 253 EUR, EUR→USD 1.12).
  It fails when:
  - a conversion of 253 EUR reads anything other than 283.36 USD;
  - a 253 sits beside its own currency key holding another currency;
  - a string prints 253 with $, ر.س, USD or SAR.

  Fail-first: making `FxDisplay::one` return the raw amount under USD makes it fail on `campaigns` straight away. It
  found one real shape on the first run, the pacing rows (`budget` 253 EUR beside `spent_currency` USD), which led to
  `budget_fx` / `spent_fx` there.
- `FxApplyCasesTest`: the Owner's explicit cases.
  - 283.36 in the table, the page, the totals and the per-campaign row.
  - The export header and column.
  - A budget through `BudgetFx`.
  - USD+EUR+SAR = 483.36.
  - EUR→SAR = 1,062.60 (cross).
  - Per-date rates (110 + 112).
  - No rate: withheld everywhere, plus an open admin alert.
  - A rate older than 7 days: used, ⚠.
  - Editing a rate then recompute gives 290.95, with the original untouched.
  - A 13.33 USD rule against 12 EUR gives 13.44 and matches; the SAR rule reads 50.40.
  - A withheld spend is never compared with a rule.
  - The production report is anonymous and count-only.
- `FxAmount.test.tsx`: the display spec (icon, tooltip text, both currencies, withheld, stale, totals, coverage note).
- Changed existing tests:
  - `ReportColumnsExportTest`: the platform header gains the original-currency column, by Owner spec.
  - `approvals.test.tsx`: a withheld request now reads «— ⚠ بانتظار سعر صرف (EUR)» instead of «36 € — بدون تحويل».

## Production report and runbook

`php artisan fx:conversion-report [--months=2026-10,2026-11] [--json]` is read-only and count-only. Accounts and projects
appear only by anonymous index. `docs/currency-engine/G-runbook.md` has the post-deploy commands. Not run on production:
no access.

## Deferred, and why

- **Pacing and alert ratios across currencies.** `budgetPacing`'s `remaining`, `pace` and `consumed_pct` still refuse a
  cross-currency division (`currency_mismatch`); the converted figures travel beside them as `budget_fx` and `spent_fx`.
  Turning the refusal into a converted ratio changes the meaning of alert, digest and client-rung outputs pinned by nine
  suites (BudgetPacing*, AlertSweep baseline, DigestsYesterday, ClientBudgetRung, NovBudgetFxDefault, SpendLimitGovernance,
  BudgetExplanation, AlertFigures, MetricsTest). It needs an Owner decision on which date's rate a period's plan is judged
  at.
- **Metric figures in a SAR display currency.** Aggregated metrics stay in the USD reporting basis, converted per row at
  ingest. Spend, CPA and the charts are labelled USD, which is truthful. Budgets, approvals, client spend and comparisons
  follow the workspace display currency (`FxDisplay`). Converting every aggregate USD→SAR on the way out touches all of
  `MetricsAggregator`'s consumers. With the default display currency USD the two agree.
- **Stored rows whose original currency label is wrong** (`stored≠account ccy`) are counted, not repaired (see above).
- **PDF header.** The client PDF prints from the Chromium print route, which is the live report page (builder J's
  surface). The rate facts are in CSV/XLSX and the methodology rows. The print page does not yet render `ReportFxFacts`.
- **Dashboards built on figures already in the reporting basis** (agency overview, `/me/dashboard`, portfolio) are walked
  by the registry test and carry no unconverted amount, but they do not render `FxCoverageNote` yet. That would touch the
  shell and dashboard layout D is changing.
- **Shared-link and client-portal surfaces** (`client/*`, report share links) carry no spend by the permanent client-report
  rules, and live in another portal. They are listed in `NOT_WALKED`.
- **Commerce orders** keep their own conversion at ingest (`CommerceCurrency`, an `ENGINE_HOMES` entry), as F deferred.
- **`features/funding/money.tsx` defaults** (`currency = 'SAR'` on `formatMoney` / `Money`). The ledger is SAR by contract
  and the callers pass it. The default was left alone and not added to any list.
- **A failure that predates this branch.** `src/lib/countedNouns.test.ts` flags `features/agency/AgencyHeadline.tsx`, a
  file this branch does not touch (it comes from the merged batches).

## Verification run here

Targeted serial run (`ptest.sh`, own DB, filter Metrics|BudgetPacing|AlertSweep|Digest|ClientBudget|ProjectBudget|Campaign|Report|Client|Ga4|Launch|Agency|RoleDashboard|Portfolio|Currency|Fx|Money|Automation|Agent|Funding|SpendLimit|Invoice|Billing|Measurement|ProjectLink): 3,706 passed, 0 failed, 1 skipped. pint clean and phpstan clean on every changed
backend file. `tsc -b` clean. vitest green on campaigns, agents, funding, clients, alerts, projects, billing, analytics,
content, reports, dashboard, agency, lib and components, except the pre-existing countedNouns failure above. The full
suite has not been run (the caller runs it).
