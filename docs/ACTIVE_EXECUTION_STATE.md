# Active execution state

_Reconciled from Git, the Matrix, the owner ledger and Production evidence on 2026-09-09._

## Where Git is

`origin/main` = `8316d3a7`, deployed and Production-verified. Merged and deployed this session:
`125333db` (#303) · `5a6da0c2` (#304) · `5fa2efa1` (#305) · `c51228b8` (#306) · `b63be1d2` (#307) ·
`42013fce` (#309) · `13fa09dd` (#310) · `f348306e` (#308) · `c2743cb8` (#311) · `5894e478` (#312) ·
`84924a35` (#313) · `a5f37677` (#315) · `129d9a86` (#316) · `c0466881` (#317) · `8316d3a7` (#318).

## Closed on Production evidence

| row / id | evidence |
|---|---|
| 50, AGGREGATION-TRUTH-001, SANDBOX-PROD-001 | coverage `state: complete`, `expected: [meta, linkedin, snapchat]`, `excluded: []`; totals unchanged at 9,842.78 / 566 |
| 2 | Meta media probe: 12 usable, 0 unusable, 0 no-still; every asset `image/jpeg` with real dimensions |
| 3 (server half) | zero assets fetching HTML, on Meta as well as Snapchat |
| 25, 26, 31, 32, 72 | verified earlier this session; see the ledger rows |

## The sandbox defect took four attempts — the failures are the lesson

#313 filtered rows flagged `raw->sandbox` under a non-sandbox account; those rows claim `snapchat`.
#315 expected only providers holding an account; four unlinked sandbox accounts exist. Both
deployed, both left Production unchanged. Each was built on a row shape I had INFERRED, with a
fixture written to match the inference — so the tests confirmed the premise instead of the world.

The blindness had one cause: every report was ACCOUNT-scoped while coverage is PROJECT-scoped.
#316 and #317 made the project's own distribution readable and it answered in one line —
`sandbox 2, 2 flagged`. The rule that works needs no account condition: a row the sandbox
connector wrote is synthetic wherever it is filed.

## In flight

Branch `queue/multiselect-and-terminology` — the report-scope picker's five entity axes moved to
the shared `MultiSelectField` (owner row 47 / UX-MULTISELECT-SCALE-001), plus a terminology
collision found in the same file: the creatives axis was labelled «Ads» / «الإعلانات», identical to
the ads axis, in both languages. 2,231 frontend tests green.

## Blocked — each on ONE named thing

| item | blocked by |
|---|---|
| `/app/content` acceptance (rows 1, 4, 5, 6, 12, 17, 18) | an authenticated session; no failing card named |
| Row 7 collection tiles, row 11 recovery | Snapchat `/token` 429 (row 11 also needs a genuinely expired row to exist) |
| Sandbox row removal (51), SNAP-AD-STATS-ROUTE-001 | a VPS shell; AUTHORIZED, NOT EXECUTED |
| Meta catalog re-mapping (8) | `ads_management` not granted |
| MAIL-SEND, digests, budget email | provider credentials |
| Production geometry (5, 16, 19) | the browser pane renders at `docW: 0` |
| `MONEY-USD-002` snapshot semantics | an owner decision |

## Known CI-health signal, not raised as a requirement

Five CI-only gate failures this session, five different specs, two browsers, none reproducible
locally, none reachable by the change under test — twice on DB-only console changes. Every symptom
is the page being ready before its data is. Same class as GATE-WK-001, which had a real fixable
cause. The owner's call whether to open a row.
