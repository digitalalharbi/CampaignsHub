# Currency engine — builder F notes (FX-ENGINE-001)

Branch `feature/currency-engine-f` (based on `feature/currency-engine`). Not pushed, no PR. Scope: the engine, the admin
API and the admin page. Builder G applies the contract to every display and aggregation path.

## The contract for G

### Golden rule

A monetary value is ONE object `{amount, currency, date}` (`App\Domains\Metrics\Fx\MoneyValue`, TS `MoneyValue` in
`frontend/src/lib/money/fx.ts`). A bare number is not money. Every path that shows, sums or compares money converts it
through **one** function:

```php
app(\App\Domains\Metrics\Fx\FxEngine::class)->convert($amount, $from, $to, $date): FxConversion
// or ->convertValue(MoneyValue $money, string $to)
```

- Base system currency USD (`CurrencyConverter::BASE`). Indirect pairs go through USD (EUR→SAR = EUR→USD × USD→SAR).
- Raw platform numbers are never modified. The original travels inside the result.
- `FxConversion::toArray()` is the money serializer's shape (TS type `FxConversion`):

| key | meaning |
|---|---|
| `original_amount`, `original_currency`, `date` | the platform's own figure, untouched |
| `currency` | target currency |
| `converted_amount` | **null exactly when withheld** (never 0, never the original) |
| `rate`, `rate_date`, `source` | the rate used and the day it was in force; source e.g. `manual:a@x`, `official-peg:SAMA`, `ecb_reference`, `…:inverse`, `a × b` for a cross |
| `kind` | `manual` · `pegged` · `automatic` · `cross` · `identity` |
| `recorded_by` | the person who recorded a manual rate |
| `stale` | the rate is older than 7 days (still used — show ⚠) |
| `withheld` | no valid rate for the amount's date |
| `pending_label` | «بانتظار سعر صرف (EUR)» when withheld |
| `rates_page` | `/admin/settings/currency-rates` when withheld |
| `legs` | the two rates of a cross |
| `via` | `fx.convert` — the guard stamp (`fx.guard` when MoneyGuard withheld it) |

Frontend: render it only with `formatConversion(c, ar)` (`lib/money/fx.ts`) → `{text, withheld, stale, link, basis}`. A
withheld figure renders the label and the link; `basis` is the audit line for a tooltip.

### Rate selection (`CurrencyConverter::resolve`)

1. a dated rate ON the amount's date, else the nearest earlier within 7 days (`config('fx.rates.stale_after_days')`, now 7);
2. else a PEGGED rate — applies to every date, never stale;
3. else the nearest earlier dated rate older than 7 days — used, `stale: true`;
4. else the inverse pair by the same rule; else for two non-USD currencies a cross through USD; else `null` → withheld.

A rate past `effective_to` is not in force. A manual rate wins over an automatic one for the same day (enforced at write
time). Ingest (`ReportingCurrency::normalise`) already uses `resolve()`, so stored figures follow the same rule; the extra
keys (`kind`, `stale`, `recorded_by`, `legs`) are additive to the old array shape.

### Writing rates

Only `App\Domains\Metrics\Fx\RateLedger::record(base, quote, rate, date, kind, source?, effectiveTo?, note?, by?)`.
Append-only `currency_rate_versions`; `currency_rates` keeps one live row per pair/day (natural key unchanged); an audit row
(`fx.rate.recorded|edited|shadowed`) on every write. `MoneyMarksSourceGuardTest` fails on any other writer in `app/`.

### Display currency

`FxPolicy::displayCurrencyFor($tenantId)` → `USD|SAR` (workspace choice in `tenants.settings.fx.display_currency`, else the
platform default in `platform_settings.fx_policy`). It is a DISPLAY choice: money is still summed in USD; convert the USD
figure (or the original) to the display currency with `FxEngine::convert` on the way out.

### Guard hook for serializers

- Converted money leaves the backend as `MoneyGuard::serialize($conversion)` (= `toArray()`).
- A serializer holding an amount it must show in another currency but has no conversion for calls
  `MoneyGuard::unconverted($where, $amount, $currency, $display)` and emits what it returns (a withheld shape) — it records
  the bypass for the daily check and logs once per request.
- `fx:monitor` (daily 03:00) scans the last 3 days of `daily_metrics` and logs `fx.money_guard.*` errors for a converted
  figure with no `exchange_rate` while its original is in another currency (the «253 $» shape) or with an unknown unit,
  plus the hook's counters; it exits FAILURE then. The rates page shows the last run.

## Admin API (platform admin only)

| | |
|---|---|
| `GET /api/v1/admin/fx/rates` | feed, unmet pairs, live rates (kind, version, note, who, end), `currencies` (used by ad accounts: accounts, last rate to USD, date, kind, status `valid|stale|missing|base`, withheld count), `policy`, `workspaces`, `alerts`, `guard` |
| `POST /api/v1/admin/fx/rates` | `{base, quote, rate, rate_date, effective_to?, note?, kind?: manual|pegged}` → new version |
| `GET /api/v1/admin/fx/rates/history?base=&quote=` | every version, newest first |
| `POST /api/v1/admin/fx/preview` | `{amount, from, to, date}` → `FxConversion` (converted rounded to 2) |
| `POST /api/v1/admin/fx/recompute` | `{from, to, apply?}` ≤93 days; dry run unless `apply`; re-converts `daily_metrics`, `entity_daily_metrics` and refreshes every budget's spend; audited `fx.recompute.applied` |
| `PUT /api/v1/admin/fx/policy` | `{display_currency?: USD|SAR, auto_source_enabled?}` (auto default OFF) |
| `PUT /api/v1/admin/fx/workspaces/{tenant}/display-currency` | `{display_currency: USD|SAR|null}` |

Free automatic source: switched on, `fx:rates` uses the ECB euro reference rates (public, no key) when no `FX_RATE_DRIVER`
is set, and records `X→USD` for every floating currency used by an ad account, kind `automatic`, source `ecb_reference`.

## Workspace USD→SAR default (builder C) — superseded

- Migrated to ONE platform pegged row `SAR→USD 0.266666666667` (`official-peg:SAMA`, dated 1986-06-01, applies to every
  date) by `2026_11_17_100000_fx_rate_versions_and_policy` (skipped if a pegged SAR/USD row already exists).
- `GET /funding/rate` returns the platform rate (`managed_by: platform`, `can_manage: false`, `kind`, `stale`, `withheld`);
  `PUT /funding/rate` answers 410. `FxRateBar` is read-only. `CurrencyConverter::resolveForWorkspace()` now returns the
  platform engine's answer. `WorkspaceFxDefault` (class) removed; `workspace_fx_defaults` (table) left in place, unread.
- Test fixtures that used SAR as a stand-in floating currency start without the migrated peg (`WithoutMigratedSarPeg`, 16
  test classes). Their assertions are unchanged.

## The confirmed defect («253 € on the campaign page, 253 $ in the campaigns table»)

Reproduced as fixtures, not end-to-end on a screen:

- engine: `FxEngineTest::test_253_eur_at_1_12_is_283_36_usd_and_never_253_dollars` (283.36 USD, rate, date, kind);
- stored figures: a rate recorded after the 253 EUR was stored stays unapplied until recompute —
  `FxAdminApiTest::test_recompute_updates_stored_numbers_from_their_originals_and_summarises_the_difference`;
- the stored «253 as dollars» shape is caught by `FxSafetyNetTest::test_the_daily_guard_logs_a_stored_figure_that_bypassed_convert`;
- the display shape (an amount printed with a currency it is not in) is what the frontend guard refuses. Nine live
  instances exist today and are listed in `moneyMarksGuard.test.ts` → `KNOWN_BYPASSES` (owner G); the ratchet test fails
  when one is fixed and left in the list:
  - `analytics/AnalyticsPage.tsx` `currency={currency ?? 'SAR'}`
  - `analytics/OverviewCompositions.tsx` `currency={d.reportingCurrency ?? 'SAR'}`
  - `analytics/PathAnalysis.tsx` `money(value, currency ?? 'SAR')`
  - `campaigns/CampaignComparison.tsx` ×2 `money(…, r.budget_currency ?? 'SAR')`
  - `campaigns/CampaignStructureTab.tsx` `money(s.daily_budget, s.currency ?? 'SAR')`
  - `clients/TabAnalytics.tsx` `currency={a.currency ?? 'SAR'}`
  - `content/CreativesPage.tsx` `currency={data?.currency ?? 'SAR'}`
  - `funding/labels.ts` the add-amount label with a literal ر.س mark

  Also for G: the campaigns table prints spend/cost with `summary.currency` (the reporting currency) and the budget with
  `budget_currency` — trace the exact row whose 253 carried `$` against real data; the most likely sources are a figure
  that reached `value` without a rate, or a budget/entity amount formatted with the reporting currency instead of its own.

## Verification

- Backend (`ptest.sh`): the FX set (FxEngineTest, FxAdminApiTest, FxSafetyNetTest, MoneyMarksSourceGuardTest, the 28
  earlier currency test classes, NovBudgetFxDefaultTest) green; full parallel suite run after the engine commit:
  4 failures, all SAR-as-stand-in fixtures, fixed with `WithoutMigratedSarPeg`; final full parallel run at `f7d11c18e`:
  8,661 tests, 0 failures (1 skipped).
- `fx.alert_on_link` (default on) exists because the link-time alert row takes a UUID; `SyncSweepCharacterisationTest`
  (deterministic UUID sequence) switches it off.
- Frontend: `tsc -b` clean; full vitest green after the numeral-convention fix; oxlint clean on changed files (one
  pre-existing warning).
- pint and phpstan clean on every changed backend file.
- Fail-first: the engine tests were written against behaviour the old `resolve()` did not have (no cross through USD, no
  stale flag, no peg-for-every-date, overwrite instead of versions) — reasoned from the old code, not executed against it.
- Not browser-verified: the admin page was not opened in a running app (no local stack started — Owner order: no heavy
  server work); it is covered by component tests.

## Deferred, and why

- Applying the contract to every display/aggregation path and emptying `KNOWN_BYPASSES` — builder G's scope.
- Commerce orders are not in recompute (their conversion lives in `CommerceCurrency`, a separate pipeline); their
  withheld counts are shown on the page.
- Recompute runs synchronously, bounded to 93 days and chunked; a queued job for longer periods was not built.
- Demo seeders still insert `currency_rates` rows directly (no versions); the ledger guard covers `app/` only.
- Matrix / ledger / RESUME_STATE not edited (Owner order for this batch).
