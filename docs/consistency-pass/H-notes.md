# Builder H — one project budget, limits inside it, finance ↔ budgets

Branch `feature/consistency-pass-h` (based on `feature/consistency-pass`). Not pushed. Status: `IMPLEMENTED_NOT_VERIFIED`
until the full suite, CI and a live walk.

## Commits
1. ONE-BUDGET-001 — one source for the project budget (`ProjectBudgetReader`).
2. ONE-BUDGET-001 — spend limits become thresholds of the budget (`funding:convert-spend-limits`).
3. FIN-WALLET-001 — a confirmed top-up payment is appended to the budget ledger (`WalletTopUp`).

## Task 1 — one source
- `App\Domains\Funding\Services\ProjectBudgetReader` reads the approved budget (`FundingBalance`) over the budget's OWN
  period: approved, deposits, settlements, spent, remaining, used ratio, `cover_days` («تكفي X يومًا», last three
  complete days — the same rate as `run_out_date`), `projected` (spend + that rate to the period's last day; open
  period → null), alert steps. It takes no date range. `has_budget: false` = «بدون ميزانية».
- Readers wired: `/agency/client-budgets` (`project_budget`, `project_budgets`, `platform_campaign_budgets`),
  `/app/clients/{id}` (`budget`), new `GET projects/{project}/approved-budget`, `/me/dashboard` (`money`, `units` —
  manager/pm/finance share them; `money.source = approved_budget`, campaign roll-up under
  `money.platform_campaign_budgets`), `/projects/{id}/spend-limits` (`meta.budget`). Export already read
  `FundingBalance`; the test asserts it.
- UI: home cards (`ClientCards`, `overviewMath`), client page `ClientBudgetCard`, campaigns strip + pacing line
  (`ApprovedBudgetPacing`, same test ids), spend-limits page panel, agency dashboard table renamed «ميزانيات الحملات في
  المنصات» with a «للاطلاع فقط» note. Shared pieces: `features/funding/ApprovedBudget.tsx`.
- «أضف ميزانية» → `/budgets?add=1` with the names in router state (never in the URL).
- Money-withheld memberships: `FundingReach::moneyVisible()` (one query for many projects).

### Before / after (fixture logic, no production data)
| Project | Home card before | Home card after |
|---|---|---|
| University (approved 5,069 SAR, spend 4,404 SAR in its period, platform campaign budgets 3.41K $) | «1.28K $ من 3.41K $» — sum of platform campaign budgets and the 30-day window's spend | «4,404 ر.س من 5,069 ر.س», المتبقي 665 ر.س; the platforms' 3.41K $ only as «ميزانيات الحملات في المنصات» |
| A project with no approved budget | the platform campaign budget as if it were the budget | «بدون ميزانية» + «أضف ميزانية» |
Changing the dashboard period (7/30/90) changes only the platform line, never the budget figures (`OneProjectBudgetTest`).

## Task 1b — limits → budget
- Additive columns on `spend_limits`: `project_funding_id`, `converted_at`, `legacy_snapshot` (row as it was, read-only).
- `php artisan funding:convert-spend-limits` = dry run (prints counts: seen, linked, budgets_created, needs_budget,
  no_rate, no_client, already). `--apply` writes. Rules: project has a budget → link; active project-scope limit on a
  project with no budget → becomes its budget (one `initial` entry, key `limit:<id>`, amount to SAR via `BudgetFx`,
  limit's own steps merged into 50/75/90/100) then link; platform/account/campaign or inactive limit with no budget →
  left, counted `needs_budget`. Idempotent; deletes nothing; events untouched.
- Automatic part: opening a project's limits links them once the project has a budget; a new limit is born linked.
  Creating a budget FROM a limit is command-only (money appears in the ledger) — **Owner: run the dry run on
  production, then `--apply`.**
- Alerts: the budget announces its highest newly reached step (50/75/90 by default) once per cycle
  (`funding_notices` state `pct:NN`); «running low»/«over» already said in the cycle outrank it. Spend-limit alerts
  (75/90/100 per limit) are unchanged.

## Task 3 — finance ↔ wallets
- `invoices.purpose` (`wallet_top_up`) + `invoices.project_id` (additive). `POST billing/clients/{client}/invoices/wallet-top-up`.
- A payment recorded by finance (same transaction) or a gateway payment settled by a verified webhook (afterCommit,
  idempotent on the payment id, failure reported) → `WalletTopUp` → `AddFundingPayment` (null actor = system entry) →
  a `top_up` deposit net of the invoice's tax share. `meta.source` names it; manual entries carry
  `{type: manual, reference}`; the drawer shows «دفعة في النظام» / «إدخال يدوي».
- Wording: the UI says «شحن رصيد إعلاني», not «محفظة» — `noForbiddenTerms` forbids «محفظة/المحفظة».

## FX
`App\Domains\Funding\Support\BudgetFx::convert()` is the single adapter (used by the limit conversion). When builder F's
central `convert(amount, from, to, date)` lands, rewire only that class. `FundingBalance`/`AddFundingPayment` still call
`CurrencyConverter` as before (not touched).

## Tests run (targeted, serial, own DB)
OneProjectBudgetTest, SpendLimitsBecomeBudgetTest, WalletTopUpTest (each fail-first verified by reverting the source),
plus filters Funding|SpendLimit|NovBudget|NovSpendLimit|AlertSweep|DigestsCadenceBudgets|RoleDashboard|BudgetRanOut|
ClientBudgetRung|ProjectBudgetRung|ClientTeamProjectCeiling|Invoice|Billing|Payment — all green. Vitest: overview,
campaigns, clients, funding, budget, roleDashboard, agency, lib (guards) — green. `tsc -b` clean; pint and phpstan on
touched files clean.

## Deferred, and why
- **Reports and their exports** (`features/reports`, report budget sections) still read the campaign pacing; client
  reports must not carry spend/budget framing (PERMANENT client-report rules) and builder J owns reports — left.
- **Automation rules / alert evaluator** (`AlertEvaluator`, `SpendLimitGovernor`) still measure limits on their own
  amounts; they now carry `project_funding_id` so a later change can read the budget. Builder I owns automation.
- **Marketer dashboard** (builder I) untouched.
- `/agency/billing` page itself (quotes/payments lists) not redesigned; the top-up flow lives on the client billing tab.
- The limit conversion does not run `FundingAccountGuard` (one account → one budget); a limit-made budget on a project
  whose accounts already feed another budget is possible — check the dry-run list before `--apply`.
- VAT on top-up invoices follows the client's profile; whether ad-balance top-ups carry VAT is an Owner decision.
