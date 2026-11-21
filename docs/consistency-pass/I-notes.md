# Builder I — misleading dashboard KPIs and automation indicators

Branch `feature/consistency-pass-i`. Not pushed. Matrix and ledger untouched (the closing session owns them).

## Task 2 — dashboard KPIs (DASH-KPI-OBJECTIVE-001, CONS-I-002)

- `ObjectivePerformance::byFamily()` returns one block per objective family: result, its OWN cost per result (from
  `ObjectiveResult`, so orders/leads/clicks-or-visits/views/installs each use their own unit and label), and for sales only
  `roas`, `roas_campaigns`, `sales_campaigns`. ROAS is over sales campaigns that have both revenue and spend and no withheld money.
  A family the platform sent nothing for is `result: null` + the «بدون بيانات» label, never 0.
- `GET /agency/overview` gains `by_objective`. The agency dashboard drops the blended «تحويلات المنصة» and «تكلفة النتيجة» cards, shows
  one card per family, and the ROAS card says «دخلت N من M حملة مبيعات». The platform table no longer shows per-platform conversions,
  cost per conversion or ROAS (they summed every objective); it shows spend and share of spend.
- Campaigns banner «N حملة خارج الحساب»: now a collapsible listing each campaign with its reason (no budget / different currency /
  partial spend). `excludedReason()` in `lib/money/portfolioBudget.ts` is the one predicate behind both the count and the list.
  **Merge note for H:** I extracted the exclusion predicate out of `portfolioBudget()` (same behaviour, same count). If H changes
  the `refused` list or the comparable rule, change `excludedReason` and the two stay in step.

## Task 5 — automation indicators (CONS-I-AUTOMATION)

- Currency adapter: `Metrics/Services/DisplayCurrencyAdapter` is the ONE place builder I converts platform currency to display
  currency (today `ReportingCurrency::forProject`, via the existing `CurrencyConverter::resolveForWorkspace`). **When the central
  `convert()` from builder F lands, repoint this class only.**
- Daily budget consumption: today's spend (reporting currency) over the object's daily budget converted to the same currency. The
  budget comes from the campaign, else from the sum of its ACTIVE ad sets (read-only: a budget change is not asked of the campaign
  from a sum). No budget, no spend today, or no rate: the value is «غير متوفرة», the condition has no figure and the rule is skipped
  with the reason (`no_daily_budget` / `no_spend_today` / `no_rate`).
- Campaign age: new additive nullable `external_campaigns.platform_created_at` (migration 2026_11_17_100000), filled by the
  connectors from the platform's own stamp (Meta `created_time` — now requested — TikTok `create_time`, Snapchat/X `created_at`,
  LinkedIn `changeAuditStamps.created.time`) and never wiped by a sweep that lacks it. Rows synced before it fall back to the stamp
  kept in `raw`; with none the age is «غير متوفرة», never the first-sync date. Google Ads and OpenAI Ads send no creation stamp
  in what we read, so their campaigns have no age until a source is added.
- Dry run («تجربة دون تنفيذ»): each result carries `readings` (Arabic indicator name with window, value in display currency with its
  mark, per-value reason) and `reason` (the exact «بيانات غير كافية»: missing creation date, unknown status, no spend / below the
  minimum, or the indicator the platform did not report). The technical keys (`cpa@7d`) stay only in the history column.
- Approvals: `agent-proposals` responses carry `display` (budget and current budget in the display currency, `converted`, source
  currency, `reason: no_rate` when no rate exists). The card shows «39.6 $ (36 €)»; with no rate it shows the platform figure and says
  no conversion was made. The platform figures in `payload` are untouched.

## Verification

Backend: `AgencyOverviewTest` (+2), `AutomationIndicatorsTest` (8, new), `AgentApprovalGatewayTest` (+3) and the neighbouring
Automation/Agent/Import/guard suites: 180 passed. pint, phpstan clean. Frontend: tsc -b clean; vitest for agency, campaigns budget,
agents, automation, money, noForbiddenTerms.

## Deferred

- Meta ad-set and ad level age: an ad set or ad is given its campaign's age (the label is «عمر الحملة»).
- Existing campaigns get a real age on their next sync (Meta only after the next campaign sweep requests `created_time`).
- The per-platform results by objective are not on the dashboard platform table; Analytics already holds them.
- Browser/e2e walk of the new dashboard cards: not run here (no Playwright in this pass); the full suite is the owner's.
