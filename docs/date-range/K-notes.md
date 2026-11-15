# Builder K — one date range for the whole system (DATE-RANGE-001)

Branch `feature/date-range-picker-k`. Not pushed; no PR. Matrix and ledger untouched.

## The rule

Every rolling preset ends **yesterday**, as the ad platforms' do. Only presets whose name says «اليوم» or «هذا»
include today, and those are flagged `ongoing` (badge «جاري — غير مكتمل»). The period is **Riyadh time (GMT+3)**;
each account's own dates keep its own zone, and an account in another zone is named in the tooltip.

Presets (all end yesterday unless noted): اليوم (today, ongoing) · أمس · اليوم وأمس (ongoing) · آخر 7/14/28/30/90 يوم ·
هذا الأسبوع (starts Sunday, ongoing) · الأسبوع الماضي · هذا الشهر (ongoing) · الشهر الماضي · آخر 3/6/12 أشهر
(today minus N months, to yesterday) · من بداية السنة (1 Jan to yesterday; on 1 Jan, today alone) · طوال المدة
(from the page's earliest day, floor 2024-01-01, to yesterday) · مخصص.

## Where it lives

| What | File |
|---|---|
| Period math (TS) | `frontend/src/lib/period/periodMath.ts` |
| Selection, URL, storage (per user, try/catch) | `frontend/src/lib/period/periodState.ts`, `usePeriod.ts` |
| Picker (popover / bottom sheet) | `frontend/src/components/ui/DateRangePicker.tsx`, `RangeCalendar.tsx` |
| Same panel for a period owned by a document | `frontend/src/components/ui/DateRangeField.tsx` |
| Badges «جاري» / «بيانات غير مكتملة» | `frontend/src/components/ui/PeriodStatus.tsx`, `lib/period/coverageApi.ts` |
| Period math (PHP twin) | `backend/app/Domains/Metrics/Services/PeriodResolver.php` |
| Coverage per account/day | `PeriodCoverage.php`, `PeriodController.php`, routes `metrics/period`, `metrics/period-coverage` |
| Agency overview accepts `compare_from`/`compare_to`; max span 365 → 1100 days | `AgencyOverviewController.php` |

## How it travels

Address: `from`, `to`, `compare` (`previous` | `last_year` | `custom` with `cfrom`/`cto`). Order of precedence: address,
then what this user last chose (memory for the tab, `localStorage` key `ch.period.v1.<userId>`), then «آخر 7 أيام».
A link carries dates, not a preset name: the label is inferred again on opening. Old `?days=N` links open the matching
preset. «مستخدمة مؤخرًا» = the user's last three distinct choices.

## Data accuracy — what the backend says and does not say

A day is missing for an actively bound ad account when it has no `daily_metrics` row and no `success`/`no_data` sync
run covers it. Cause named: `sync_failed` when a `failed`/`partial_mapping` run covers it, otherwise just «no figures».
Never an invented reason, no error text passed on. Not gaps: today; days before the account's first row; an account
that never synced (`never_synced`).

## Pages updated

/agency/dashboard (AgencyHeadline) · /agency/campaigns · campaign page (every tab) · /agency/ad-groups and /agency/ads
(EntityWorkspacePage) · /agency/analytics and the dashboard surface (AnalyticsPage) · report builder and live-link builder
(DateRangeField; a new report starts on the system period) · budget hint on dashboard, campaigns, campaign page, analytics.

## Decisions and deferred (with the reason)

- **Role dashboards (/agency/home)**: they have no period control. Their windows are fixed operational ones (last three
  complete days for pace, as the backend states in `window`). Left alone on purpose.
- **Budget numbers**: spend limits (`/spend-limits`) never took a range and are proven independent by test. The campaign
  budget pacing endpoints (`metrics/budget*`) still receive the display range because the pacing definition belongs to the
  budgets batch (builder H). Moving them onto a budget-owned window is theirs; the hint is shown meanwhile.
- **Comparison on every KPI and chart**: wired end to end on the agency dashboard (server compares against the chosen window).
  The metrics endpoints on campaigns/analytics take one window; a second parallel read for every KPI and chart overlay is
  deferred. The picker, the URL and `compareRange` are ready for it.
- **Report/export headers**: the period is already written in report headers. The comparison is not stored on a report
  (needs an additive column); deferred.
- **Saved views** keep a day count only (`date_range.days`), so a custom range is not restored by a saved view.
- **Agency-wide «بيانات غير مكتملة»**: coverage is per project. The agency dashboard shows only the «جاري» badge; its own
  existing coverage notes still apply.
- **ReportScopePicker** from/to is an optional content narrowing (may be empty); left as date fields.
- **طوال المدة** starts at the page's earliest day when it supplies one, else 2024-01-01.

## Comparing «آخر 30 يوم» with Meta and Snapchat — steps for the Owner (no platform access here)

This session has no platform access (read-only rule, connections stopped), so no comparison was run.

1. In CampaignsHub open the project, choose «آخر 30 يوم». It is the 30 days ending yesterday (e.g. 16 Oct → 14 Nov 2026
   when today is 15 Nov). Note the spend, per platform (dashboard/analytics platform table).
2. Meta Ads Manager: the same ad account, date range «Last 30 days» (also ends yesterday), time zone = the account's.
   Columns: Amount spent. Snapchat Ads Manager: custom range with the same two dates, Spend column; note the account time zone.
3. Compare per account, in the account's currency (CampaignsHub shows the project currency; convert only with the stored rate).
4. If «بيانات غير مكتملة» is shown, open its tooltip first: the listed days are known gaps, not a mismatch.
5. If an account's time zone is not Riyadh (named in the tooltip), expect a small gap at the day edges; compare a
   range of whole days in that zone.
6. Optional database cross-check: `select metric_date, sum(value) from daily_metrics where project_id = '<project>' and
   external_account_id = '<account>' and metric_key = 'spend' and metric_date between '<from>' and '<to>' group by 1 order by 1;`

## Verification

See the final report of this batch for the commands and results.

### Results (2026-11-15, this worktree)

- Backend (`ptest.sh`, targeted): `PeriodResolverTest` 21, `PeriodCoverageTest` 10, `AgencyOverviewTest` 6, route guards
  (`ProjectRouteCapabilityCoverageTest`, `ProjectIsolationProbeTest`, `RouteNamesInCodeResolveTest`,
  `PublicSurfaceInventoryTest`) 9 — all pass. Pint and phpstan clean on touched files.
- Frontend: `tsc -b` clean; full `vitest run` 741 files green after updating the tests that asserted the old 7/30/90 chips.
- Playwright (own ports 8196/5296/5396, DB `mediabuying_k_e2e`, fixed clock 2026-11-15): `e2e/period-picker.spec.ts`
  12/12 on chromium and 12/12 on webkit (390 phone sheet and 1366 two-month popover included).
- Firefox: the browser does not launch in this environment (the first test fails in 1 ms, the same limit builder E recorded).
  Not run, not claimed.
