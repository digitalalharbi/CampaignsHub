# Builder N4 — §7 aggregation + currency, and the N1/N3 deferrals

Branch `feature/project-lifecycle-n4` (from `feature/project-lifecycle`: earlier batches + N1 + N3 + currency engine F+G).
Not pushed, no PR. Matrix, ledger and RESUME_STATE untouched. No production access; every id in code, tests and this file is
a fixture or a placeholder. Status `IMPLEMENTED_NOT_VERIFIED` until the lead's full suite, CI and the production reads in §5.

## 1. Root cause — the live client's three different numbers

Symptom (Owner spec §7): one live client, Google account in EUR, SAR budget 50,000 (open-ended). Budgets page «14,235 ر.س
(28%) ناقص سعر صرف»; clients list «$4,229.08» (30 days); dashboard + projects summary «$0 of $8.75K». Admin rates: EUR→USD
manual dated 2026-11-09, SAR→USD pegged.

Production runs `origin/main`, which has none of builders F/G/H/N1. Read from that code (and confirmed or narrowed by the
queries in §5), five causes stack:

| # | Cause | Where (origin/main) | Which number it explains |
|---|---|---|---|
| C1 | **No chained conversion.** `CurrencyConverter::resolve()` tried EUR→SAR and SAR→EUR only — no cross through USD. Every EUR account-day of the budget was withheld. | `Metrics/Services/CurrencyConverter.php`, `FundingSpendRefresher` (EUR→SAR) | Budgets «14,235 ر.س ناقص سعر صرف»: the non-EUR spend only. |
| C2 | **Rate date selection.** A rate applies on and after its own date. EUR days before 2026-11-09 have no rate at all, so they are withheld everywhere — correct — but every page except the budgets page dropped them **silently** (`sum(value)` skips NULL). | ingest `ReportingCurrency::normalise` | Clients list smaller than the truth, with no statement. |
| C3 | **Stored-at-ingest drift.** `daily_metrics.value` is converted ONCE at ingest. A EUR day stored before the 2026-11-09 rate was recorded stays NULL until somebody presses «إعادة حساب». The budgets page converts from the ORIGINAL at refresh time, so for the same days it counted spend the clients list, projects summary and campaigns page did not. | `daily_metrics.value` vs `funding_spend_days` | Clients list ≠ budgets for the same days. |
| C4 | **Different aggregation functions per page.** Budgets: `funding_spend_days` over the budget's own period. Clients list: `sum(value)`, last 30 days, every project of the client. Dashboard + projects summary: `ClientBudgetRollup` / `portfolioBudget` over `MetricsAggregator::budgetPacing()` — the platforms' campaign budgets and only the rows whose spend and budget are in one currency. | `ClientBudgetController`, `lib/money/portfolioBudget.ts`, `ClientPortfolioStats` | Three unrelated numbers. |
| C5 | **The 8.75K and the $0.** `budgetPacing` keeps a campaign only when its spend currency equals its budget currency. The EUR campaigns (budget EUR, spend USD or partly withheld) are `currency_mismatch` / `partial` → excluded. What remains is USD-budgeted campaigns with no spend in the window: **8.75K = Σ `unified_campaigns.total_budget` of those campaigns**, and «$0» their spend. `origin/main`'s `ClientBudgetController` reads EVERY project of the client, so the most likely holder of those USD campaign budgets is the archived test project (Q2 confirms or refutes it). | `MetricsAggregator::budgetPacing`, `ClientBudgetRollup`, `ClientBudgetController` | Dashboard + projects summary «$0 of $8.75K». |

Two suspects the brief named were checked and are **not** causes of these figures on their own:

- **Client state.** `origin/main` filtered clients by `archived_at` only, never by `client_status`; an «onboarding» client was
  not hidden (Q3 checks `archived_at`). Rule 4 is now pinned by a test.
- **Archived project counted.** Yes on `origin/main` (clients list `sum(value)` and the dashboard roll-up read every project) —
  it inflates the clients list only if the archived project spent in the last 30 days (Q1/Q5), and it is the likely home of the
  8.75K (C5). N1's live scope removes it; tested here on all five pages.

One more cause found in this branch's own code while reproducing it:

- **C6 — the open-ended budget's start ignored withheld spend.** `FundingSpendRefresher::countsFrom()` took the first day with
  `value > 0`, so a budget typed in after the spend began started at the first CONVERTED day; the days with no rate before it
  were neither counted nor named. Now: the first day of real spend (`COALESCE(original_amount, value) > 0`).

## 2. What changed (the fix at the source)

**One aggregation function — `App\Domains\Metrics\Spend\SpendLedger`.**
- `accountDays(projectIds, from, to)` is THE spend row definition (selected accounts, one figure per campaign/day, estimated
  and modeled rows left out). The budget refresher now builds `funding_spend_days` from it, so the budget and every total are
  the same rows.
- `read(projectIds, from, to, currency)` converts every account-day from its ORIGINAL currency with `FxEngine::convert()` for
  its own date (manual, pegged, cross through USD, stale, expired — the engine decides), LIVE projects only (N1's scope; client
  state never read). A part with no rate is excluded and named: `excluded[]` (currency, account name, original amount, days),
  `statement` «سعر صرف غير متوفر: EUR — Account (400.00 EUR، 4 أيام) — مستبعد من الإجمالي.», `stale[]` with
  «سعر قديم — بتاريخ …». The total is `null` only when nothing at all converted.

**Who reads it.**

| Page | Endpoint | Now |
|---|---|---|
| Budgets | `GET /funding` | `spent` = `funding_spend_days` (built from `accountDays`), + `fx` (exclusions, statement, old rates) |
| Clients list | `GET /app/clients` | `spend` = ledger (USD, 30 days); `spend_fx` = partial total + exclusions (was: withheld whole «—») |
| Dashboard | `GET /me/dashboard`, `GET /agency/client-budgets` | denominator = the approved budget (H) of LIVE projects; `budget_fx`/`spent_fx` = the same SAR figures through FxEngine in the display currency; `fx` |
| Projects summary | `GET /portfolio/overview` | `spend.by_currency` (per stored basis, kept — legacy SAR-basis rows stay segmented, trend/contribution add up to it) + `spend.reading` (ledger) |
| Campaigns | `GET /projects/{p}/metrics/summary` | `current.spend` (stored, now released — below) + `spend_reading` (ledger) |

**Stored figures follow the rate.** `RateLedger::record()` now RELEASES, after commit, every stored figure withheld for want
of a rate that is convertible now (`FxRecompute::run(..., onlyWithheld: true)`, from the rate's day, at most 93 days, then the
touched budgets are refreshed). A converted figure is never re-priced automatically: editing a rate still needs the admin's
«إعادة حساب» (FX-ENGINE-001, unchanged). This supersedes F's «a rate recorded after a withheld row stays unapplied until
recompute» for NULLs only; `FxAdminApiTest` was adjusted to say so.

**Expired manual rate.** `CurrencyConverter::leg()`: a rate past `effective_to` is used when nothing in force is newer, flagged
`stale` + `expired`; `FxConversion` carries `expired` and `stale_label` «سعر قديم — بتاريخ YYYY-MM-DD». `FxMonitor` raises an
internal platform-admin alert `fx_rate_expiring` (in-app platform inbox, the existing mechanism; builder M's engine is not on
this base) `fx.rates.expiry_warning_days` (default 3, env `FX_RATE_EXPIRY_WARNING_DAYS`) days before `effective_to`, and clears it
when a newer open rate exists.

**Frontend.** `FxSpendNote` (components/money) renders the backend's statement + rates link + old-rate label; `FxTotalAmount`
shows a partial total with a ⚠ carrying the statement. Wired on the budgets row and drawer, clients list, role dashboard,
projects summary and the campaigns overview.

## 3. Five-page comparison (fixture `LifecycleAggregationCurrencyTest`, same period 2026-11-01 → 11-15)

Fixture: «onboarding» client; live project with a Google EUR account; 100 EUR/day on 11-05 … 11-14; EUR→USD 1.08 manual dated
11-09 (11-05 … 11-08 have no rate; 11-09 … 11-11 were stored before the rate was recorded); SAR budget 50,000 open-ended; an
archived project under the same client with 1,500 USD of spend and an 8,750 USD campaign budget. Truth: 6 × 108 = **648.00
USD = 2,430.00 SAR**, plus 400 EUR with no rate.

| Page | Before (this branch's base) | After |
|---|---|---|
| Budgets | 2,430.00 ر.س, «ناقص سعر صرف», no account named | 2,430.00 ر.س + «سعر صرف غير متوفر: EUR — Account EUR (400.00 EUR، 4 أيام) — مستبعد من الإجمالي.» |
| Clients list | 324.00 $ in `spend`; `spend_fx` withheld whole («—») | 648.00 $ (partial) + the same statement |
| Dashboard | 2,430 of 50,000 ر.س (H already), no exclusion named | 2,430 of 50,000 ر.س; denominator in USD via FxEngine 13,333.33 $; statement |
| Projects summary | 324.00 $ | 648.00 $ + statement |
| Campaigns | 324.00 $ (`current.spend`), «مبالغ محجوبة» rows only | 648.00 $ (`current.spend` = `spend_reading`) + statement |

Max difference between the five pages after: 0.00 (≤ 0.01 asserted). The archived project's 1,500 USD and 8,750 USD budget
appear on none of them (asserted). On `origin/main` the same fixture would also show the budgets page without any EUR (C1) and
the dashboard as the comparable-campaign roll-up (C5) — those two were already closed on this base by F (cross) and H
(approved budget), and are now pinned by this test.

Precision note: `funding_spend_days.amount_sar` is stored per account-day in cents; a total can differ from the ledger's by at
most 0.005 per day in a fixture whose conversions are not whole cents (the test's conversions are exact).

## 4. Part B — N1/N3 deferrals finished

- `LiveProjectsAdapter` deleted; `AccountDayCoverage`, `DataArrivalJudge` and the evaluator's link paths read `LiveProjects`
  (active + paused; draft is no longer judged).
- `AlertEvaluator::onlyLiveProjects()` — every rule type, tenant-wide or project-scoped, drops findings about non-live projects
  (one read of the tenant's live ids per sweep); a rule scoped to a non-live project finds nothing; an open alert of a non-live
  project closes quietly with `project_not_live` (no «recovered» notice). Sweep statement bound 560 → 562.
- Digest «ملخص الربط» and the no-data state: `AccountDayCoverage::projectReadings()` / `noDataState()` (account time zone,
  settle window, one cycle) replace `LinkHealth`'s Riyadh-clock readings; the reading shape is unchanged.

## 5. Production reads (READ-ONLY, counts and sums only — the lead runs them; pass ids as psql variables, never commit them)

`psql -v client_id=… -v project_id=…` (the live project) — outputs contain no ids, names or emails.

```sql
-- Q1 (archived counted? C4/C5) projects of the client by state, with their spend in the last 30 days
select p.status, (p.deleted_at is not null) as deleted, count(distinct p.id) as projects,
       count(m.*) as spend_rows, round(coalesce(sum(m.value), 0)::numeric, 2) as spend_value_usd
from projects p
left join daily_metrics m on m.project_id = p.id and m.metric_key = 'spend' and m.metric_date >= current_date - 30
where p.client_workspace_id = :'client_id'
group by 1, 2 order by 1;

-- Q2 (source of 8.75K, C5) campaign budgets in the platforms by project state and budget currency
select p.status, u.budget_currency, count(*) as campaigns, round(sum(u.total_budget)::numeric, 2) as total_budget
from unified_campaigns u join projects p on p.id = u.project_id
where p.client_workspace_id = :'client_id' and u.deleted_at is null and u.total_budget > 0
group by 1, 2 order by 1, 2;

-- Q3 (client state) — never a cause unless archived_at is set on origin/main
select client_status, (archived_at is not null) as archived from client_workspaces where id = :'client_id';

-- Q4 (rates in force, C1/C2) — no ids
select base_currency, quote_currency, coalesce(kind, 'legacy') as kind, rate_date, effective_to
from currency_rates
where base_currency in ('EUR', 'SAR', 'USD') and quote_currency in ('EUR', 'SAR', 'USD')
order by base_currency, quote_currency, rate_date desc;

-- Q5 (C2 vs C3) the live project's EUR spend rows: withheld before the rate date (C2) or after it (C3 = ingest drift)
select (m.value is null) as withheld, (m.metric_date >= (select min(rate_date) from currency_rates
          where base_currency = 'EUR' and quote_currency = 'USD')) as on_or_after_first_rate,
       count(*) as rows, min(m.metric_date) as first_day, max(m.metric_date) as last_day,
       round(sum(m.original_amount)::numeric, 2) as original_eur
from daily_metrics m
where m.project_id = :'project_id' and m.metric_key = 'spend' and upper(m.original_currency) = 'EUR'
group by 1, 2 order by 1, 2;

-- Q6 (C1) the budget ledger: SAR counted and days with no SAR figure, by original currency
select coalesce(original_currency, '(mixed)') as currency, count(*) as days,
       count(*) filter (where amount_sar is null) as days_without_sar, round(coalesce(sum(amount_sar), 0)::numeric, 2) as sar
from funding_spend_days where project_id = :'project_id' group by 1 order by 1;

-- Q7 (C1) does any EUR↔SAR rate exist directly? (origin/main cannot chain through USD)
select count(*) from currency_rates where (base_currency, quote_currency) in (('EUR', 'SAR'), ('SAR', 'EUR'));

-- Q8 (C6) first spend day vs the budget period start
select (select min(metric_date) from daily_metrics where project_id = :'project_id' and metric_key = 'spend'
          and coalesce(original_amount, value) > 0) as first_real_spend,
       (select min(metric_date) from daily_metrics where project_id = :'project_id' and metric_key = 'spend' and value > 0) as first_converted,
       (select starts_on from project_funding where project_id = :'project_id' and voided_at is null) as stated_start;
```

How to read them: Q7 = 0 and Q6 EUR `days_without_sar` = all EUR days → C1. Q5 `withheld & on_or_after_first_rate` > 0 → C3
(release fixes it on deploy only for figures re-read after a rate write; run the admin «إعادة حساب» once for the window after
deploy). Q5 `withheld & not on_or_after` → C2 (honestly excluded, now named). Q2 rows on an `archived` project totalling ≈ 8,750
→ C5 + archived counted. Q8 `first_real_spend < first_converted` and `stated_start` null → C6.

## 6. Tests (fail-first)

- `LifecycleAggregationCurrencyTest` (5): five pages equal for the same period (≤ 0.01) with the excluded part named on each;
  archived never counted + onboarding client shows its spend; expired rate used with «سعر قديم — بتاريخ 2026-11-09» + the
  3-day admin alert; deleted rate excludes only the EUR part with a flag; open-ended budget counts from the first real spend.
  On the base: 4 of the first 4 failed (probe figures in §3).
- `LifecycleLiveScopeAlertsTest` (2): a tenant-wide rule never evaluates an archived project and closes its open alert quietly;
  the digest link reading uses the account-time-zone coverage rule. Both failed on the base.
- `FxAmount.test.tsx` (+3): partial total, the note, nothing when complete.
- Adjusted: `AlertSweepBatchedReadsTest` (bound), `FxAdminApiTest` (release on record).

## 7. Deferred, and why

- **Every `MetricsAggregator` figure other than the headline spend** (CPA, charts, campaign rows, reports, digests, rule
  readings) still sums the stored `value`. With the release on record they agree with the ledger except between a rate EDIT
  and the admin's «إعادة حساب» (F's decided model). Converting every aggregate at read time is a rewrite of the aggregator.
- **Notifications / digests money wording.** The digest still says «فيه إنفاق مسجّل بعملة ما لها سعر صرف، فما نعرضه كرقم» when
  any row is withheld (OperationalDigestModel::noDataReason) instead of the partial total + statement; the alert ratios keep
  G's `currency_mismatch` refusal (needs the Owner's decision on which date's rate judges a plan, as G recorded).
- **Reports (client-facing)** carry no spend by the permanent client-report rules; the CSV/XLSX rate facts are G's.
- **The home `ClientCards` card** shows the approved budget (H) but does not render the `fx` note yet (payload carries it).
- **Builder M's notification engine** is not on this base; the expiry alert uses the existing platform inbox (`platform_alerts`).
- **Production**: the queries above, the post-deploy «إعادة حساب» for the drifted window, and the browser walk are the lead's.
