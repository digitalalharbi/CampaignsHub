# Active execution state

_Reconciled from Git, the Matrix, the owner ledger and Production evidence on 2026-09-08._

## Where Git actually is

`origin/main` = `f348306e`, deployed. Merged and deployed this session, in order:
`125333db` (#303) · `5a6da0c2` (#304) · `5fa2efa1` (#305) · `c51228b8` (#306) · `b63be1d2` (#307) ·
`42013fce` (#309) · `13fa09dd` (#310) · `f348306e` (#308).

## Closed on Production evidence

| row / id | claim | evidence |
|---|---|---|
| 26 | result types are not one Results number | the owner's own report showed 582 results over 350 conversions + 232 sales at one 17 USD cost; now «· مزيج» with the parts named |
| 31 | operator diagnostics out of the client payload | `reasons` empty on every coverage block; the sync exception gone; verdict and contributors survive |
| 32 | no internal id on a client link | `id`/`campaign_id` gone from ads and grouped ads; no whole-value UUID |
| 72 | coverage names its platforms | conversion `complete` `[snapchat]` against 9,437.86 |
| 25 | derived ratios recomputed | every ratio recomputed from aggregates and matched; spend reconciles at 9,842.78 across four surfaces |
| 3, 9, 10 | media truth, REPORT surface | 3 images decoded 1080×1920, zero broken, zero HTML-as-image, 3 coverless films each carrying their sentence |
| CREATIVE-AD-RELATION-001 | many ads share one creative | 1,524 creatives reachable through `external_ads.creative_id`, none orphaned |

## The product defect the new gate found

WebKit never fires `seeked` under `preload="metadata"` without a user gesture, so `VideoPoster` —
which reported that event as proof of a painted frame — left every coverless film as a blank card
in Safari and on iOS. It reads `readyState` now and paints in 2.7s on the browser that sat blank
for twenty seconds. Deployed in `c51228b8`.

## Blocked — each on ONE thing, and blocking nothing else

| item | blocked by |
|---|---|
| `/app/content` acceptance (owner row 4, IMPLEMENTED_NOT_VERIFIED) | authenticated session; no failing card named |
| Collection tiles (owner row 7) | Snapchat `/token` 429. Command and workflow input are shipped and correct |
| Sandbox quarantine → also `SNAP-AD-STATS-ROUTE-001` | VPS shell. AUTHORIZED, NOT EXECUTED; safeguard intact |
| Meta catalog re-mapping | `ads_management` not granted |
| «henka» binding | `connection=error` |
| `MAIL-SEND`, digests, budget email | provider credentials |
| Branding / DQ / objective-depth / report-parity evidence | authenticated surfaces |
| Production geometry (rows 5, 16, 19) | the browser pane renders at `docW: 0` |
| `MONEY-USD-002` snapshot semantics | an owner decision, deliberately not taken unilaterally |

## Next executable unit

Everything independently executable is exhausted; the queue is the blocked list above. The first
Snapchat action of the next day should be ONE `integrations:probe --structure --shapes` run,
before any `diagnose` run, because every dispatch spends a token exchange against the quota that
is currently exhausted.
