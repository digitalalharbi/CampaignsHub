# ACTIVE EXECUTION STATE — 2026-09-07

The current control plane, and nothing else. Requirements and their status live in
`docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`; how to resume from cold lives in `docs/RESUME_STATE.md`.
If this file and the Matrix disagree, the Matrix wins — and if the Matrix and the running product
disagree, the product wins.

## Main

`711a5f69` — «Ads and Content are named for what they are — and Content keeps its analytics» (#302).
Deployed, success.

## In flight

`fix/content-is-written-in-contents-words`. #302 renamed the tab and left the vocabulary INSIDE it
unchanged: the panel read «أداء الإعلانات», the first column «الإعلان», the description «from
ad-level data», the narrowed empty state «No ad was reported». These figures are
`creative_daily_metrics` at the CONTENT grain, and one creative can be carried by several ads, so a
row headed «الإعلان» tells a reader they are looking at one ad's numbers when they are not.

## Production evidence taken after each deploy

| what | how | result |
|---|---|---|
| the blank-image root cause | `integrations:probe --media` per provider | Meta **12 / 0**, Snapchat **9 / 0**; zero cards fetch HTML |
| «undefined» and the compact rule | the live client report, in a browser | funnel 6.66M / 40.4K / 14.6K / 4.54K with exact titles; no `undefined`; no full-width counts |
| the funnel ratio that lied | same | «165% ⚠» with «هذه المرحلة أكبر من التي قبلها…» behind it |
| story ads | forced sweep `success records=11852`, then the report re-read | the three composites now render an image or say «فيديو — لم ترسل المنصة صورة غلاف له» and play |
| the abandoned-run reaper | a forced sweep that had been refused | the stale-run guard no longer fires; the sweep queued and finished in 52s |

## What is blocked, and by whom

`(#200) Ad account owner has NOT grant ads_management or ads_read permission` — the bound Meta
account, which synced `records=58` earlier the same day. Provider-side, not a code path. It blocks
ONLY the re-mapping of six Meta catalog rows; they already draw a real thumbnail rather than a web
page, so nothing is blank while it waits.

`Primemode` → «henka» is bound and ACTIVE with `connection=error`. Needs the owner's credentials.

## What this session cannot close

`/app/content` and `/agency/analytics` are authenticated and no session here reaches them. The media
rows and the Ads/Content naming stay open until the owner looks. A probe from the datacentre is not
the owner's screen.

## What the live estate actually says — traced 2026-09-05/06, read-only

`integrations:diagnose` on the VPS, calling no provider and writing no row. Three authorised
advertising providers, three different answers, and only one of them was ever a software defect.

| provider | accounts | bound to | structure | metrics | downstream |
|---|---|---|---|---|---|
| Snapchat | 309 | **Project 1** | 4×/day `success`, 11,716 records | 30-min `success`, raw 87 → parsed 87 → mapped 87 → **stored 1044** | 3,420 rows / 26 days · 9,446.29 spend · 38,405.14 revenue USD · 89 campaigns |
| LinkedIn | 10 | **project «لينكدن»** — not Project 1 | 4×/day `success`, 11 records | 30-min `success`, raw 8 → parsed 8 → mapped 8 → **stored 63** | 71 rows / 9 days · 390.44 spend USD · 6 campaigns |
| Meta | 4 | **Project 1** | `no_data`, **0 records** | `no_data`, 0 → 0 → 0 → **0** | nothing |

**LinkedIn is not broken.** It is bound to a different project and feeding it correctly, with
«rows in ANY OTHER project: 0». It is absent from Project 1 because it was never bound to Project 1
— project isolation working, and the reason nobody could see that was the Integration Center saying
«يعمل» without saying WHICH project it feeds.

**Meta is not a software failure either.** The bound account is `RazzahAvenu` /
`act_1500383245036671`, timezone **America/Los_Angeles** — Meta's default for an ad account that has
never been configured. The other three discovered Meta accounts are all Asia/Riyadh, and the
Snapchat account running this business is «RazzahAvenu Self Service». The run status is `no_data`,
not `failed`: Meta answered 200 with an empty list, which rules out a permission refusal, a
pagination or field bug, and an app-mode limit — each of those errors rather than returning nothing.

## The one decision that is not mine

**Which Meta ad account should be bound to Project 1.** Binding is the owner's explicit choice
(«do NOT silently auto-assign an account to a project»), and Meta's live-data proof cannot progress
until an account that has campaigns is bound. Recorded as BLOCKED_OPERATIONAL_EVIDENCE, not as a
defect and not as VERIFIED.

## Closed since the last update, each Production-verified

- **The sandbox write path.** `ProjectIntegrationController::sync()` ran
  `SandboxAdvertisingConnector` unconditionally against whatever binding was passed, which is how
  `sbx-cmp-1`/`sbx-cmp-2` reached the live Snapchat AND Meta accounts. The connector is resolved from
  the account's own provider through the canonical registry and fails closed. #292's outbound filter
  stays as containment.
- **The census stopped calling contamination «discovery».** Production now prints
  `provider campaigns=0` and `SANDBOX-CONTAMINATED rows=2  stored total=2` where it used to print
  `campaigns discovered=2` — verified on the live Meta account after deploy.
- **Connection health ≠ data health.** `AccountHealth::NO_DATA`, outside `NEEDS_ATTENTION`, and the
  connector card says «متصل بلا بيانات / connected, no data».
- **The Meta credential probe.** `grant_type=client_credentials` at Meta's documented app-token
  endpoint, POSTed so no secret enters a URL, token never leaving the method, with a regression that
  fails if anyone adds «Invalid verification code format» to the generic string list.

## Next, in order

1. Safe production cleanup of the `raw.sandbox` rows now that the write path is closed (§3) —
   count before/after, quarantine only what provenance proves, destroy no live history.
2. Cross-platform regression: one project, three providers, every surface and every provider filter
   (§10).
3. Aggregation truth: additive vs derived vs results vs reach vs revenue vs currency vs coverage
   (§9).
4. Then the remaining Matrix.

## Matrix, parsed from the file at this commit

| status | rows |
|---|---|
| VERIFIED | 458 |
| PARTIAL | 26 |
| IMPLEMENTED_NOT_VERIFIED | 24 |
| IN_PROGRESS | 17 |
| BLOCKED_EXTERNAL_CREDENTIALS | 17 |
| BLOCKED_OPERATIONAL_EVIDENCE | 11 |

**67 executable rows remain.** Counted programmatically from the status column, never from memory.
