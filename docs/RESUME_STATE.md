# START HERE — 2026-08-23, metric-truth chain in flight

Read this file and `git log origin/main` first. **Do not re-audit the whole project.**
The next session's work is already chosen and ordered at the bottom of this file.

---

## 1. Current main

```
origin/main = 568584e  (#73 merged and deployed 2026-08-23 09:16 UTC)
```

## 2. Merged AND deployed this session

| PR | id | what it fixed |
|---|---|---|
| #71 | `3d092c8` | `ANALYTICS-PROVENANCE-001` — the Demo badge was a literal in four pages' JSX; now derived from `MetricsAggregator::provenance()` (live/demo/mixed/none) |
| #72 | `562b3eb` | `SNAP-CREATIVE-METRICS-LIVE-001` — the Creative Library's default order used `last_active_at`, a column **nothing in the pipeline had ever written** (only the demo seeder). Now recorded on delivery, sorted `NULLS LAST`, with a backfill |
| #74 | `d2f3e19` | `DEMO-LIVE-AGGREGATION-ISOLATION-001` — `MetricsAggregator::base()` had **no `is_demo` filter at all**; live totals silently summed demo rows. Policy derived per scope, not per window |
| #73 | `568584e` | `CREATIVE-MONEY-TRUTH-001` — `creative_daily_metrics` had **no currency column**; USD figures were rendered under a hard-coded «SAR». Also: `formatMetric` defaulted to SAR at 20 call sites; the fatigue alert was gated on a withheld-zero and silently never fired |

## 3. Open PRs — exact state

Branch protection requires up-to-date branches and the gate takes **~60 minutes**, so every merge
puts the rest `BEHIND`. **Never merge a stale branch; never reuse a green result from an old base.**

| PR | branch | head | vs main | CI | next action |
|---|---|---|---|---|---|
| #73 | `fix/creative-money-truth` | `5f3fdafe03ec` | updated onto `d2f3e19` | backend ✅ frontend ✅ gate ⏳ | wait for gate → merge → deploy → **live-verify** (§5) |
| #75 | `fix/creative-sync-provenance` | `8c686c6b9969` | **BEHIND** | backend ✅ frontend ✅ gate ❓ | rebase → fresh CI. **See §6 — unexplained 500** |
| #76 | `fix/creative-frontend-ads` | `cf760b711aa4` | **BEHIND** | backend ✅ frontend ✅ gate ⏳ | rebase → fresh CI → merge → verify `creative.ads[]` in browser |
| #77 | `fix/snap-creative-assets` | `50e1b8d373fc` | **BEHIND** | backend ✅ frontend ✅ gate ⏳ | rebase → fresh CI → merge → real structure sync → verify assets render, no credentials in URLs |
| #78 | `fix/objective-aware-kpis` | `8a2490ccdf72` | needs rebase after #73 | backend ✅ frontend ✅ gate ⏳ | rebase → fresh CI → merge → verify KPI selection in real UI |

Order matters: **#73 first** (the others touch the same content files and `formatMetric`'s signature).

### Stray local branches — no work lost
`fix/creative-presenter-ads-backend` (ahead 1) and `feat/snap-creative-metrics-writer` (no upstream)
show as unmerged because they were squash-merged under different SHAs. Their content **is** in main
(`loadMissing('ads')` and `syncCreativeInsights` both present). Safe to delete.

## 4. LIVE_VERIFIED (real production evidence)

### #72 — LIVE_VERIFIED as of 2026-08-23, after #73 deployed the diagnostic

```
creatives with a last active day : 86 of 1451
creatives with any figure        : 86 of 1451
creative_daily_metrics rows      : 819   (was 814 — sync is live)
```

The two counts match exactly. Before #72 this column was **null for all 1,451** — nothing in the
pipeline had ever written it. Every delivering creative now has a last active day, so the library's
default sort has real input. This is the proof row-counts could never give.

### Production is healthy after #73's migration
```
daily_metric rows : 1716 across 13 day(s), latest 2026-08-23   (was 1704 — still ingesting)
as the platform reported it: 4,343.62 / 13,713.37 USD
286 money row(s) WITHHELD — no USD→project rate exists
campaigns visible : 89 external, 89 linked   (was 87 — improved)
rows in ANY OTHER project: 0
account currency  : USD
```
The money migration moved nothing it should not have: campaign money is still withheld rather than
wrong, and tenant isolation holds.


- Snapchat structure sync, queue re-delivery, 89 campaigns / 187 ad squads / 5,706 ads / 1,451 creatives.
- **Snapchat ad account currency is `USD`** and present — `connection=connected timezone=Asia/Riyadh currency=USD`.
  This is why creative money must never be labelled SAR.
- Creative-level metrics ingest and grow: 814 rows, latest `2026-08-23`, **86 of 1,451** creatives with figures.
- Campaign money withheld correctly: `4,336.87 / 13,713.37 USD` reported, `0.00 / 0.00` in project
  currency, **284 rows withheld** for want of a USD→SAR rate. Rising between runs, so the sync is live.
- `rows in ANY OTHER project: 0` — tenant isolation holds.

## 5. VERIFIED by tests, NOT live — and why

#72 is now LIVE_VERIFIED (§4). **#71, #73 and #74 are still VERIFIED only**, for a specific reason
found while verifying:

**The provenance diagnostic cannot answer in console scope.** It printed `live rows: 0, demo rows: 0`
on a project that demonstrably holds 1,716 rows. The block immediately above it shows
`impressions (control) : 0`, which that diagnostic's own comment documents as the tell that *the
query sees nothing at all* rather than *the money is missing*. `--downstream`, which queries the DB
directly instead of through `MetricsAggregator`, sees everything.

So the aggregator-based diagnostic blocks are scoped wrongly under the console (no request-bound
project context). **This is a diagnostic defect, not evidence about #71/#74** — and it is the next
thing to fix before those two can be live-verified. Do not read `0/0` as «no demo contamination».

The two diagnostics themselves ship **inside #73** and are now deployed:

- `WHAT THE PROVENANCE BADGE READS` — live/demo row counts (proves #71 and #74)
- `creatives with a last active day : N of M` — proves #72's backfill actually ran

Until #73 deploys, there is no way to check any of them against production. Row counts alone are
**not** proof for #72 — the defect was ordering, not volume.

### #73 live-verification checklist (run after deploy)
1. `gh workflow run production-diagnostics.yml --ref main -f provider=snapchat -f hierarchy=true -f linked=true`
2. Confirm: account currency `USD`; provenance badge block shows live rows > 0 and demo rows 0;
   `creatives with a last active day` > 0.
3. Confirm creative money renders as **`4,128.93 USD` + «conversion unavailable»**, never `0 SAR`,
   never a USD figure wearing a SAR label.
4. Confirm `creative_daily_metrics` has `original_currency='USD'`, `spend` NULL, `spend_original` set.
5. Browser-check `/content`: first page must contain **delivering** creatives.

## 6. Known open problem — DO NOT skip

**#75's gate failed with a 500 on `/app/integrations` (chromium) and the cause is NOT established.**

- It passes locally on that branch.
- `MetricSyncRun::logRow()` — the serializer that page uses — does not touch the new
  `creative_status` / `creative_rows` / `creative_error` columns.
- **But #75 is the PR that migrates `metric_sync_runs`**, so it must not be dismissed as unrelated.
- #76 and #77 sit on the same base *without* that migration; their gate results help isolate it.

If it reproduces after rebase, get the real server error (Laravel log / failure artifacts). Do not
re-run and hope. A previous WebKit failure in this project was called flake once and was a genuine
cookie-timing race.

## 6b. Gate instability is real — but it does NOT explain #75

Measured across three branches this session, each failing a different, unrelated spec:

| PR | spec | shape |
|---|---|---|
| #73 | `client-portal.spec.ts:53` | **webkit only**, passed locally on webkit; post-reload assertion at a 15s timeout |
| #76 | `registration-onboarding.spec.ts:191` | **passed chromium in 7.0s, failed firefox in 29.3s — same run**. A timeout |
| #77 | `simplification-agency.spec.ts:40` | **webkit only**, 25.6s. A timeout |
| #75 | `expansion-surfaces.spec.ts` `/app/integrations` | **HTTP 500**. Not a timeout |

**#73's failure cleared on re-run with no code change**, and its gate then passed in 1h2m. That is
direct evidence for the flakiness reading of the three timeout-shaped failures.

The first two are one browser failing what another passed in the same run, at durations that say
timeout. `playwright.config.ts` sets `workers: 1`, so the gate runs 144 tests × 3 browsers serially
and takes ~60 minutes; under a loaded runner these tests are marginal.

**Do not use that as cover for #75.** A 500 is a server error, not a slow page, and #75 is the PR
that migrates `metric_sync_runs`. It stays open and unexplained (§6). Get the real Laravel error
before forming a view.

Do not "fix" the two timing-shaped failures by raising timeouts without evidence — a previous WebKit
failure here was called flake once and turned out to be a genuine cookie-timing race.

## 7. NOT COMPLETE — newly discovered, explicitly open

**#78 exists; the objective work is NOT done.** These are open:

### Analytics — 4 of 11 tabs missing
Present: performance, platforms, campaigns, funnel, store, budget, quality.
**Missing: Ad Set / Ad Squad, Ads, Creative, Objective.**
Blocked upstream, not a UI gap — see ingestion below.

### Ingestion — no ad-set or ad level metrics exist at all
`daily_metrics` is keyed by campaign; `creative_daily_metrics` by creative. **There is no ad-set or
ad level metrics table.** Those tabs cannot be built until the pipeline supplies them.
The path is already cut: `SnapchatConnector::fetchWindow(..., $breakdown)` parameterises the level
and `campaignSeries()` reads it — the same mechanism that made creative-level work.

### Creative metric catalog — columns absent
`creative_daily_metrics` has **no** `leads`, `installs`, `registrations`, `in_app_events`.
Also needed per provider support: reach, frequency, engagements, video-depth metrics, and the rest
of the objective-specific set.
**Consequence today:** an app-install creative headlines with **spend alone** (#78 filters out what
the table cannot answer). Thin and true, where `roas` was rich and false. This is a recorded gap.

### Objective filtering
Must become a real backend-scoped filter in Analytics, not merely an enum plus `headline()`.

### Platform analysis
Must be fully populated from real provider data.

### Reports
Must reconcile and refresh from the same canonical metric + money contracts as
Dashboard / Analytics / Campaign Details / Content, for the same scope and window.

### Provider matrix
Snapchat first (only provider implementing `ReportsCreativeInsights`), then **TikTok → Meta →
Google Ads → X → LinkedIn**.

## 8. External blockers

- **FX USD→SAR rate** — `AWAITING_CONFIGURATION`. Every Snapchat money row is withheld until a rate
  exists. This is correct behaviour, not a bug: each row converts itself the day a rate arrives.
- Production UI checks needing an authenticated operator session cannot be performed from the
  container; record `BLOCKED_OPERATIONAL_EVIDENCE` rather than asserting.

## 9. Next session — exact order, no re-audit

**First:** finish the PR queue above (#73 → #75 → #76 → #77 → #78), rebasing and re-running full CI
for each, merging and live-verifying one at a time.

**Then, in this order:**

1. `CANONICAL-METRIC-CATALOG-001` — provider → normalized support for every legitimate metric
   (reach, frequency, clicks, CTR, CPC, CPM, engagement, video, leads, sales, app, cost-per-result).
   A metric must be able to say REPORTED / DERIVED / ZERO / NOT_REPORTED / UNSUPPORTED / WITHHELD /
   BLOCKED / STALE — never collapsed to `0` or «لا توجد بيانات».
2. `SNAP-ADSET-METRICS-001` — real ad-squad metrics → DB → API → Analytics.
3. `SNAP-AD-METRICS-001` — real ad metrics → DB → API → Analytics.
4. `ANALYTICS-DRILLDOWN-001` — Platform → Account → Campaign → Ad Set → Ad → Creative, with
   parent/child reconciliation.
5. `ANALYTICS-OBJECTIVE-FILTERS-001` — backend-scoped objective filters + objective-aware KPIs.
6. `ANALYTICS-TABS-COMPLETE-001` — every intended tab functional on real backend data.
7. `REPORTS-RECONCILIATION-001` — all surfaces reconcile for the same scope/window/attribution.
8. Remaining providers in the fixed order.

## 10. Standing rules that produced most of this session's findings

- Never fabricate a metric; never substitute zero for unavailable; never derive across incompatible
  currencies, attribution windows, scopes or periods.
- **Do not add a metric to the UI before the pipeline can supply it.**
- Never claim `LIVE_VERIFIED` without real operational evidence.
- Recurring defect shape in this codebase: **data supplied and never consumed.** Found four times
  this session — `creative.ads[]`, `asset_url`/`video_url`, the `readRoas()` contract, and
  `last_active_at`. When adding a producer, check the consumer and assert on the STORED ROW.
