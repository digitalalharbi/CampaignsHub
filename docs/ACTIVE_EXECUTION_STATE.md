# Active execution state

_Reconciled from Git, the Matrix, the owner ledger and Production evidence on 2026-09-08._

## Where Git actually is

- `origin/main` = `c51228b8` — "The grid's video poster, proven in three browsers" (#306), deployed successfully.
- Merged and deployed this session, in order: #303 `125333db`, #304 `5a6da0c2`, #305 `5fa2efa1`, #306 `c51228b8`.
- Open branch: `media/collection-shapes` — the `--shapes` probe that unblocks owner ledger row 7.

## What was closed on Production evidence

| row | claim | evidence |
|---|---|---|
| 26 | result types are not one Results number | conversion path showed 582 results over 350 conversions + 232 sales at one 17 USD cost; now marked «· مزيج» with the parts named |
| 31 | operator diagnostics out of the client payload | `reasons` empty on every coverage block; the sync exception text gone; verdict and contributor lists survive |
| 32 | no internal id on a client link | `id`/`campaign_id` gone from ads and grouped ads; no whole-value UUID anywhere |
| 72 | coverage names its platforms | conversion `complete` `[snapchat]` against 9,437.86 |
| 25 | derived ratios recomputed | every ratio recomputed from aggregates and matched; spend reconciles at 9,842.78 across four surfaces |
| 3, 9, 10 | media truth on the REPORT surface | 3 images decoded 1080x1920, zero broken, zero HTML-as-image, 3 coverless films each carrying their sentence |

## The one product defect found by the new gate

WebKit never fires `seeked` under `preload="metadata"` without a gesture, so `VideoPoster` — which
reported that event as its proof — left the card blank. It reads `readyState` now and paints in 2.7s
on the browser that sat empty for twenty seconds. That is Safari and every iOS browser.

## What is blocked, and by exactly what

- **`/app/content` owner acceptance** — no authenticated Production session; the owner has not named a failing card. Row 4 is IMPLEMENTED_NOT_VERIFIED, not verified.
- **Sandbox quarantine** — AUTHORIZED, NOT EXECUTED. Needs a VPS shell; the workflow deliberately cannot pass `--apply` and that safeguard stays.
- **Meta catalog re-mapping** — `(#200) Ad account owner has NOT granted ads_management or ads_read`.
- **`Primemode` → «henka» binding** — reads `connection=error`; needs the owner's credentials.

## Next unit

Land `media/collection-shapes`, deploy, then run
`integrations:probe <snapchat account> --structure --shapes` in Production to read where Snapchat
puts a collection's tiles, and write the tile fetch against the shape it reports — never a guess.
