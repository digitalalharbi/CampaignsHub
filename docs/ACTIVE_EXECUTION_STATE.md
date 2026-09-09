# Active execution state

_Reconciled from Git, the Matrix and Production evidence on 2026-09-09._

## Where Git is

`origin/main` = `a3cbff33` (#327), deployed. Merged and deployed in this run:
`7778c3d7` (#320) · `6245d10a` (#321) · `71c5b37a` (#322) · `9439187c` (#323) · `a7b0b479` (#324) ·
`727d1d4d` (#325) · `179586a9` (#326) · `a3cbff33` (#327).

## Verified in Production

| unit | evidence |
|---|---|
| #320 | the live link's outline reports `performance: present: true` over `totals.spend = 10,696.54`; it reported `present: false` with «There are no figures in this window» before. The three narrative sections report `not_composed_for_a_live_link` |
| #322 | the outline's ads section reports `figures: [spend, results, ctr]` and a reason that no longer denies the money the section leads with |
| TABLE-NUMERIC-ALIGNMENT-001, partial | the public share link's detail tables, measured on Production in Arabic RTL at 1440 and 375: twelve numeric cells, zero drift, zero alignment mismatch, zero width mismatch, all tabular numerals; the PAGE does not scroll sideways at 375 while both TABLES scroll inside their own containers. Proved non-vacuous by inverting a header in the live DOM |

**Deployed but NOT Production-verified: #321.** Its four endpoints sit behind auth on `/app`
Analytics, the browser pane renders the authenticated app at `docW: 0`, and calling the API directly
would need a session token. Local proof only — fail-first, injection, and the full 3,203-test suite.
Not marked VERIFIED.

## What the run actually found

Eight units, and the useful finding came from probing the running artefact every time — never from
reading the source or this ledger.

| unit | the defect |
|---|---|
| #320 | the outline read `kpis.spend` while the live payload calls that block `totals`, so a window carrying 9,842.78 declared it had no figures. And every live link's outline claimed «no finding is supported by the figures» about data `LiveReportService` never examines |
| #321 | `platform-objectives`, `objective-leaders`, `objective-explanations` and `objective-trend` narrowed by `campaign_ids`, which nothing sends — `qf()` sends `campaign`. The chip narrowed nothing, and because the campaign is in the React Query key, switching campaigns refetched and repainted identical numbers under the new chip. No test covered any of the four routes |
| #322 | the outline declared the ads section shows «never money» while every card leads with «الإنفاق 1,071 USD» and the section is ranked by ROAS |
| #323 | **the guard could not see the defect it was written for.** The sweep measured header-box centre against cell-box centre; a `th` and its cells share one column, so that number is zero by construction. With a real alignment check it found `/app/content` drawing «الإنفاق» with its heading at one edge and its money at the other — the class the owner has reported five times |
| #325 | the root cause of the whole class: `.tnum` carries `direction: ltr`, so on a `<td>` it makes the CELL an LTR box and its `text-end` resolves opposite to its header's |
| #326 | 37 cells across 14 files, plus a source guard. `MetricTable` was never affected because it CENTRES numeric columns, and centre resolves the same in both directions — the guard's one exemption is that fact |
| #327 | the agency's invoice table had never been swept: `billingRoutes` says «Paths are absolute under /app» and the router mounts it beside `/agency/team` |

## The ledger's «Remaining» prose is the least reliable input in this repository

Clauses checked against the source this run and found stale: `CAMPAIGN-INTELLIGENCE-HUB`,
`TEAM-PROJECT-RBAC-001`, `REPORT-CREATION-UX-001`, `OBJECTIVE-ANALYTICS-DEPTH-001`,
`ANALYTICS-FILTER-TRUTH-001`'s budget and funnel propagation, `TABLE-NUMERIC-ALIGNMENT-001`'s «every
such surface is authenticated» and its «the next step is the SEED, not the assertion», and
`REPORT-DETAIL-PARITY-001`'s level-by-level list.

**One of them would have caused a regression.** `REPORT-DETAIL-PARITY-001` asked for «ad, as a rung
beneath its ad set» — work `CLIENT-REPORT-ENTITY-BOUNDARY-001` deliberately removed at the owner's
own instruction, «اسم واختيار الحملة احذفه من التقارير». Building the listed next step would have
put back what the owner had taken out. Retired rather than executed.

Read every row against the code before acting on it. Two clauses were accurate, and both produced
real defects (#321 and #323).

## Blocked — each on ONE named thing

| item | blocked by |
|---|---|
| `/app/content` acceptance (rows 1, 4, 5, 6, 12, 17, 18) | an authenticated session; no failing card named |
| #321 Production verification, geometry rows (5, 16, 19) | the browser pane renders the authenticated app at `docW: 0` |
| Row 7 collection tiles, row 11 recovery | Snapchat `/token` 429 (row 11 also needs a genuinely expired row to exist) |
| Sandbox row removal (51), `SNAP-AD-STATS-ROUTE-001` | a VPS shell; AUTHORIZED, NOT EXECUTED |
| Meta catalog re-mapping (8) | `ads_management` not granted |
| MAIL-SEND, digests, budget email | provider credentials |
| `MONEY-USD-002` snapshot semantics | an owner decision |
| `CLIENT-DIAGNOSTIC-SEPARATION-001` PDF evidence | a snapshot report's own share token, which needs an authenticated session |

## Next, in order

1. `/agency/billing/payments`, `/agency/finance`, `/agency/clients` render no `<table>` on the
   gate's seed — measured, not assumed. They join the agency sweep block the day the seed has rows.
2. `TABLE-NUMERIC-ALIGNMENT-001` stays PARTIAL: the Production observation covers one surface, one
   direction, one engine, and the share link exposes no language control so LTR cannot be exercised.
3. Keep draining PARTIAL and IN_PROGRESS rows by reading each against the code first.

## CI health — seven flakes now, still not raised as a requirement

Seven CI-only gate failures, seven specs, all three browsers, none reproducible locally, none
reachable by the change under test. Latest: `registration-onboarding` reporting `{"status":401}`
straight after sign-in, and `portal-audit` with «Load request cancelled» across fonts and API calls.
Every symptom is a surface exercised before its state is ready. Two of the last four merges needed a
rerun for this alone. The owner's call: harden the shared navigation helper, or give the gate a
bounded retry.
