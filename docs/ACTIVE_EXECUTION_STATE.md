# Active execution state

_Reconciled from Git, the Matrix and Production evidence on 2026-09-11._

## Where Git is

`origin/main` carries #355 (analytics platform decomposition) and #356 (the client report's budget
ring). Both merged, both main-branch runs green, both deployed — `campaignshub.io` answers 200 and
serves a built SPA.

**PR #357 is open** with nine units on `budget-project-rung`, rebased onto main.

## What this run found

Every defect below was found by MEASURING the running artefact — a query count, an injected guard, a
rendered cell — and none of them by reading the source or this ledger.

| unit | the defect |
|---|---|
| REPORT-ANALYTICAL-DEPTH-001 (#356) | the client report's budget ring computed its headline from five of six platforms and counted a withheld spend as zero, while the pacing table printed directly beneath it refused both. Its sibling `ClientAttention` measured materiality against a plan summed across rows the aggregator had refused to compare |
| REPORT-ANALYTICAL-DEPTH-001 (#356) | the PRINTED document read `b.spend`, a key `budgetPacingByProvider` never sends. Every client PDF showed a spend of zero against every platform, a remaining equal to the whole budget, and 0% utilization |
| BUDGET-GOVERNANCE-001 (#357) | the client rung reported a project COUNT, so a client pacing at 1.4× named no project responsible. Underneath it the pacing query ran once PER CLIENT, under a comment claiming the opposite — nine queries per client, measured |
| ANALYTICS-FILTER-TRUTH-001 (#357) | the client list's two filters ran AFTER `paginate()`, and `meta.total` stayed unfiltered: five non-matching clients returned an empty list under a total of five |
| AGGREGATION-TRUTH-001 (#357) | `spend_share` divided by `array_sum(...) ?: 1`, so a window with no spend gave every platform a definite 0%. Both API types declared it a bare `number`, and under that lie the bar drew unguarded beside the «—» the same row printed |
| AGGREGATION-TRUTH-001 (#357) | the campaign command centre defeated its own formatters at seven call sites: a campaign that had not delivered an impression showed CTR «0.0%» and results «0» |
| AGGREGATION-TRUTH-001 (#357) | «أفضل من المتوسط» in a client report was an unweighted mean of the platforms' ratios — one freak return drags it to 8.0 across an account returning 1.51× |
| ALERTS-TRUTH-001 (#357) | «Budget at risk» divided a withheld spend as zero (silent, forever) and divided riyals into a dollar budget (375%, false) |
| REPORT-TITLE-METADATA-001 (#357) | nothing inside `/app` or `/agency` ever set `document.title`: every screen carried the marketing line in its tab, bookmark and history |

## Recorded as blocked, not fixed

- **ATTRIBUTION-WINDOW-001** — both report views read `data.attribution_window`, the payload has
  never carried it, and `NormalizedMetric::$attributionWindow` defaults to the literal `'default'`
  with no connector ever setting it. Wiring it would print «أساس الإسناد: default». The blocker is
  upstream, with Meta API access already blocked.
- **Short links `/l/{slug}`** — still needs the VPS nginx block. `BLOCKED_OPERATIONAL_EVIDENCE`.
- **Authenticated `/app` Production verification** — unavailable; no unit here is marked VERIFIED on
  Production strength.

## Method notes worth keeping

- Two fixtures were corrected rather than the guards they broke, both describing conditions no sync
  produces: alert money rows with no `project_currency`, and a ranking fixture where the mean and the
  pooled figure happened to agree — which proved nothing until it was rebuilt to discriminate.
- The `/agency/team` webkit failure on #356 was environmental: the same spec passes locally on webkit,
  and the re-run went green. Reproduced before re-running, not assumed.
- A matrix note of mine carried literal pipes into a table cell. The width guard caught it, which is
  what it is for.
