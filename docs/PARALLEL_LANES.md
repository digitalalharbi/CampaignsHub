# Parallel lanes — 2026-08-23

Read this beside `docs/RESUME_STATE.md`. That file describes the PR queue; this one describes three
branches built in parallel worktrees that do not have PRs yet.

They are deliberately kept out of the queue: each PR costs a ~60-minute serial gate, so these are
meant to land as **one or two release PRs**, not three.

## The branches

| branch | head | worktree | test DB | tests |
|---|---|---|---|---|
| `release/metrics-lane` | `504297a` | `…/CampaignsHub-lanes/metrics` | `campaignshub_test_metrics` | 7 pass |
| `release/analytics-lane` | `0add556` | `…/CampaignsHub-lanes/analytics` | `campaignshub_test_analytics` | 8 pass |
| `release/reports-lane` | `ccd6fc8` | `…/CampaignsHub-lanes/reports` | `campaignshub_test_reports` | 4 pass (+69 in the reports suite) |

All three are committed and pushed. **Zero uncommitted work in any worktree.**

`release/analytics-lane` already contains `release/metrics-lane` (merged in), because it reads the
table that lane creates. `release/reports-lane` is independent of both. All three branch from
`568584e`, which is main with #73 merged.

## What each lane did

### `METRICS-BACKBONE-001` — metrics-lane

Creates `entity_daily_metrics`, the table the product had no equivalent of. `daily_metrics` is
campaign-grain and `creative_daily_metrics` is creative-grain; the **187 ad squads and 5,706 ads
between them had nowhere to put a number**. That — not a missing screen — is why Analytics has no
Ad Set tab and no Ads tab, and why building those tabs first would have meant rendering nothing.

One table for both rungs, distinguished by `entity_type`, natural key
`(entity_type, entity_id, metric_date, attribution_window)`. The attribution window is IN the key
because two windows are two measurements of the same day and summing them is a fabricated total.

Money is nullable with `*_original` and `original_currency` beside it, converted through the same
`ReportingCurrency` service as everything else — this table is built after CREATIVE-MONEY-TRUTH-001
rather than before it, so it has no money column that defaults to 0.

Catalogue extended to what the current API exposes at these grains: reach as `uniques` and
frequency as `frequency` (both provider-reported, never approximated from impressions), the four
video quartiles, `screen_time_millis` converted to seconds once at the edge, `native_leads`,
`total_installs`, `conversion_sign_ups`, `conversion_app_opens`. Verified against
developers.snap.com, August 2026.

`ENTITY_METRICS` is deliberately separate from `METRICS`: that map feeds `daily_metrics`, and a
mistake there would land on the campaign totals every surface already reads.

`MetricAvailability` names the nine states — REPORTED, DERIVED, ZERO, NOT_REPORTED, UNSUPPORTED,
WITHHELD, FAILED, BLOCKED, STALE.

### `ANALYTICS-DRILLDOWN-001` — analytics-lane

`EntityMetricsAggregator` reads the new grain on the same terms as every other surface: the same
money-truth field names (so `lib/money/contract.ts` renders an ad squad's spend through the reader
it uses for a dashboard KPI), the same demo-isolation rule, un-coalesced sums, every ratio null when
its denominator is missing or zero, frequency averaged rather than summed, and drill-down where an
**empty parent set matches nothing** rather than everything.

Separate class from `MetricsAggregator` for a structural reason: that one reads a TALL table it must
pivot, this one reads a WIDE table. Forcing both through one class would branch every query on shape
around the most delicate code in the existing aggregator.

### `REPORTS-RECONCILIATION-001` — reports-lane

`ReportGenerator` was never the problem — it already snapshots from the same metrics aggregation and
records `generated_at`, `mode`, `data_source`, `attribution_window`, freshness and a checksum.

`InteractiveReport` declared every one of those in its own type and **rendered none of them**. A
report opened a month after generation showed month-old figures with nothing on screen to say so.
Now it states snapshot-versus-live, the timestamp, and the attribution basis. A snapshot written
before the metadata existed says nothing rather than inventing a date for itself.

## The one step deliberately NOT done

**Nothing calls `UpsertEntityDailyMetrics` or `fetchEntityInsights` yet.** The storage, the ingest
action, the connector fetch and the aggregator all exist and are tested; the caller in
`AccountMetricsSyncer` does not. Until that is wired, `entity_daily_metrics` stays empty in
production.

This is recorded rather than hidden because it is the difference between "the backbone is built"
and "the Ad Set tab has data". It is the single highest-value next step.

Order after that: expose the aggregator through an Analytics endpoint → build the Ad Set / Ads /
Creative / Objective tabs → combine the lanes into one release PR → one full local gate → push.

## Parallel-worktree lessons that cost real time

- **Never symlink `vendor` into a worktree.** Composer's autoloader holds absolute paths, so the
  worktree silently runs MAIN's source — new classes appear missing and tests exercise the wrong
  code. Copy `vendor`, then `composer dump-autoload` inside the lane.
- `backend/phpunit.xml` hardcodes `DB_DATABASE=mediabuying_test`, but an environment variable
  **does** override it: `DB_DATABASE=campaignshub_test_x php artisan test`. Never share one test
  database between concurrent suites — it produces deadlocks that look exactly like real failures,
  and cost an entire misdiagnosed run earlier in this session.
