# START HERE — 2026-09-15 (reconciled from Git)

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.

**Git is the authority. This file is a summary of it and goes stale between runs — when the two
disagree, Git is right.**

## 2026-09-15, later — reconciled again, and it was stale again

`origin/main` = **`288bc48d` (#422)**. This file said `2c312917` (#420): two merges out of date, on
the run whose own header warns that it goes stale. Reconciled from Git rather than trusted.

Merged since the section below was written:

- **#421** The client's download, the unwatched function, and the line that drops the bound.
- **#422** Ask the account, not the app — the Meta access probe (`integrations:meta-probe`), the
  provider error sentence carrying its identifiers (code, subcode, fbtrace_id, request id), the ROAS
  panels that named the wrong campaign because `reported` carries summed columns and never derived
  ones, and the spend-efficiency scatter that refuses to plot a campaign missing a coordinate rather
  than placing it at the origin. **Production-verified**: the deploy ran on `288bc48d` and the
  served bundle `/assets/index-BdSC2A2l.js` carries that merge's own strings.

**Open PR: #423** — the premium launch success experience. Five checks green, the webkit gate
running. It is Phase D of the owner's closure order and is already implemented, not pending.

**Prepared locally, in queue order behind it:**

1. `needs-attention-one-definition` — one screen said «needs attention» twice from two engines; the
   flags now own the phrase. 2716 unit, 33 E2E across three browsers, fail-first proven.
2. `meta-probe-workflow` — a read-only Meta Access Probe workflow, a guard that refuses `--sync` in
   any workflow described as read-only, a provider list corrected to keys an account can carry, and
   the Meta / Google / Content production evidence recorded in the owner's ledger.
3. `mkt-boot-residual` — MKT-FIX-001 re-measurement, in progress.

### What production said on 2026-09-15, read-only, and what it settles

- **Meta** — 4 accounts, 3 bound to nothing, the one bound account failing every insight and
  structure run with `(#200) … has NOT grant ads_management or ads_read`. Downstream rows stop at
  2026-09-06. `PERMISSION_REVOKED` → **BLOCKED_EXTERNAL_CREDENTIALS**; the grant is the owner's.
- **Google** — `No external account matches that filter` for provider `google`: nothing has ever
  been connected. Asked twice, because the first ask used the diagnosis form's own suggested key
  `google_ads`, which matches no row and answers identically to an empty estate.
  **BLOCKED_EXTERNAL_CREDENTIALS**.
- **Content (Snapchat)** — structure sweeps succeeding with zero orphans at every level, 936 rows
  stored every half hour, 24 of 24 first-page cards with something to draw. The sampled creatives'
  `conversions 0 / revenue 0 / roas 0` was checked against Snapchat's own retained body and is the
  truth, not a fabricated zero. Creative-grain spend carries withheld rows and renders as the money
  contract's `partial`. Open and unproven: 160 of 1526 creatives carry any figure.

## 2026-09-15 — where this session stopped

`origin/main` = `2c312917` (#420), deploy run succeeded and the served bundle confirms it: the
production asset hashes moved (`index-n3GAhzwK.js` → `index-Dcg0rZZf.js`, `index-DmSFRYgc.css` →
`index-fMYwqRn8.css`). Nothing in #420 is reachable without signing in, so its BEHAVIOUR stays
`BLOCKED_OPERATIONAL_EVIDENCE` in this lane — the deploy is verified, the screens are not.

**#420 merged after that line was first written.** It is the Campaigns Command Center: project-scoped
status counts, server-side lifecycle filtering with the chip counts taken BEFORE the narrowing,
universal campaign drill-down as real anchors on six surfaces, four primary KPIs with every money
guarantee migrated into a compact secondary strip and re-proved there, an objective-aware return
column that renders an EMPTY cell rather than «—» where it does not apply, the Analytics Quick
Preview, the compact operational header, and attention rows carrying what is at stake.

### Merged this run, each with six green checks and a confirmed deploy

- **#416** Rules written once and applied in one place — the report itself asserted to carry only
  live figures; total ordering applied to the provider and account breakdowns, not campaigns alone;
  multi-card creatives classified as carousels rather than stills.
- **#417** The file a client receives — client exports were failing in ALL THREE formats
  (`campaign_management_entity`: the sanitiser named sections and did not know about
  `ads_platform_groups`); the Arabic brand face was absent from every client PDF (a metric fallback
  with no `unicode-range` claimed Arabic and Arial answered for it); a frame the browser had given
  up on counted as a frame; expired media was never recovered in the detailed report's platform rung.
- **#418** Two rules the product states — the four absence sentences held distinct as a property in
  both languages; every number formatter required to name a Latin-by-construction locale, which
  found `DeliveryLog` relying on a CLDR default that `ar-SA` would have flipped.
- **#419** Content Results and Cost-per-result recovered from the ad grain (`leads`, `installs`,
  `sign_ups`, `app_opens`, `page_views` existed in `entity_daily_metrics` and were dropped at the
  point of reading them; `cpl`/`cpi` were named as verdicts and never computed); Live link and saved
  document required to agree; the exact `GoogleAdsFailure` printed instead of only its bucket.

### Production-verified on the live site

- `#413` boot stability: `<html lang="ar" dir="rtl" data-theme="dark">` served, CLS 0.0000 at 390px.
- `#417` font fallback: asset hash moved `DMI56SSI` → `DmSFRYgc` and `unicode-range` is present on
  `Inter Fallback`. **Its first deploy run FAILED** (`Run Command Timeout`, the SSH action's 600s
  limit during the VPS build) and production was still serving the previous commit; a re-run landed
  it. A merge is not a deploy, and the asset hash is the proof.

Everything else merged is backend or behind authentication: `BLOCKED_OPERATIONAL_EVIDENCE` in this
lane, which holds no production credentials.

## IN FLIGHT — pick this up first

**PR #421 `shared-link-exports`** — eight commits, CI running when this was written. Full suites
green locally (backend 3626, frontend 2695). It carries:

- the client's own download surface (`/reports/shared/{token}/download/{format}`), which had NO test
  while being the file a client actually receives;
- **the same scope-dropping line found in two more controllers.** `->getQuery()` unwraps a builder
  without applying scopes — `toBase()` is the one that keeps them, confirmed in the framework source.
  `SyncRunController` counted other projects' AND other TENANTS' runs into the summary an operator
  reads to decide whether their own figures are trustworthy; `TaskController`'s project-scoped route
  counted other projects' tasks while returning this project's rows. Both proved by restoring the
  line, and the MECHANISM is now guarded — no application code may unwrap a builder past its scopes;
- `objectiveFamiliesInScope()` proved, the function standing between the product and a generic
  «Results» that sums purchases, leads and installs;
- a spend-versus-efficiency scatter component, not yet wired into the page.

Merge it, confirm the DEPLOY ran (not just the merge), then browser-verify.

## NEXT EXECUTABLE ACTIONS, in order

1. **#421 → merge → deploy → production verify.** The deploy is the step that fails silently; check
   the run, and check a served asset hash rather than the merge.
2. **Wire `SpendEfficiencyScatter` into the Campaigns page.** The component and its tests land with
   #421; the page is on main. One decision it answers that no ranking can: high spend AND high cost
   per result. It must keep refusing to plot a campaign missing either coordinate — placing it at the
   origin would put the campaigns we know least about in the corner reading «cheap and efficient».
2. **Campaigns, remaining from the owner's list:** spend-vs-efficiency scatter where semantically
   valid, objective/platform contribution, movers. Strongest/weakest, budget pacing and the trend
   already exist. Do not add a chart that does not answer a decision.
3. **Content:** story/vertical, catalog/DPA, multi-asset preview shapes. Collection tile fetch stays
   `BLOCKED_EXTERNAL_CREDENTIALS` — the Snapchat `collection_properties` shape is unobservable here
   and guessing it would be fabrication.
4. **Reports:** exports end to end for the shared public link (the same exporter, so #417's fix
   covers it — prove it), audience semantics, report-creation settings affecting output.
5. **Google steps 7–9:** need a real authorised account. `integrations:google-access --probe` now
   performs discovery through the product's own path and prints the exact failure; run it on
   production after re-authorising.
6. **Cross-surface reconciliation:** the analytics summary ↔ generated report pair and the live ↔
   detailed pair are locked by tests. Content card → popup → Content Analytics → Reports is not.

## What kept being true this session

The dominant defect was not a wrong calculation. It was **a correct answer computed where nothing
reads it**: a sanitiser naming sections instead of finding them, a media walk with the same list, an
ordering rule applied to one breakdown of three, the exact Google failure stored and printed nowhere,
`campaign_id` on every analytics row with no link on any of them, and status counts scoped by a
builder the total did not share.

It also showed up in my own work — a reconciliation reading a key that does not exist, a guard that
matched nothing, a comment claiming work not done, a `—` where the code promised nothing. The
injection step is what caught those, not the passing run.

---

## Earlier runs (kept for history)

## 2026-09-14 (later the same day) — where this stopped

`origin/main` = `e5408a58` (#412), deployed.

**This file said #404 while Git said #412 — eight merges out of date, on the run that wrote the
sentence above about going stale.** That is the point of the sentence and not an excuse for it: a
summary drifts the moment it is not rewritten, so the owner's instruction is to reconcile against Git
BEFORE choosing what to do next, and this is that reconciliation.

### Merged this run, each with six green checks and a confirmed deploy

| PR | What it was |
| --- | --- |
| #405 | An ad-set rung with no rows reads as a broken dimension, not an empty one |
| #406 | What a share link may disclose — the ceiling, the flags, and the money it hid |
| #407 | One identity, and no surface may build a second |
| #408 | The live report's cross-platform comparison — which platform did better |
| #409 | The detailed report's platform rung — what works HERE |
| #410 | The narrowing that guards the read was never put on the writes (authorization) |
| #411 | One key holds the product's name, and one answer deleted for having no reader |
| #412 | Fourteen scheduled commands already knew how much they did, and told a terminal |

### What Production can and cannot show

#408 and #409 are verified in the served bundle — the sections, both languages, the markers and the
counts are in `index-Dh0amz30.js` and `index-CtRDQBVO.js`. That is «it shipped», not «a client sees
it»: owner acceptance on a real multi-platform link needs an authenticated session this lane does not
have, and is recorded as BLOCKED_OPERATIONAL_EVIDENCE rather than left looking finished.

#410 is deployed and answers 401 to a stranger, which proves the route and the session gate and NOT
the narrowing — that needs two users in one tenant. #411's check can only prove the absence of a
regression, because its defect was visible only on an install that never set the framework's name
key. #412 has no runtime surface a reader can see.

### The owner's two latest corrections, against existing requirement IDs

- **BRAND-MARK-001** — the identity and where clicking it goes. In flight.
- **MKT-FIX-001** — the mobile marketing homepage shifting on a cold load. In flight; root cause
  found and measured, see the row.

Merged, deployed and checked this run:

- **#402** reports composition — verified in the SERVED Production bundle (`index-CU6X0AwJ.js`
  carries `summary-trimmed-note`; component names are minified away, the testid survives).
- **#403** branding removal guard — deployed, Production 200. It touched only a test and a Matrix
  line, so there is NO runtime surface to verify and none is claimed.
- **#404** one link, one ceiling — three share-token sections that ignored the ceiling. Deployed at
  its own commit. Its Production half is BLOCKED_OPERATIONAL_EVIDENCE: all three surfaces are reached
  through a share token, and issuing a Production token is the Owner's to do.

In flight: **#405** ad-set demo grain. Prepared locally behind it: the attribution unit
(`ceiling-deploy-note` branch) — the last share-token endpoint with no ceiling at all.

**What this run was really about.** A parity question — do the deck and the live link build their
shared axes from one path? — turned up a class of defect rather than a single bug: places where one
fact about a link was computed in more than one spot, and the copies disagreed. The account ceiling
was honoured by the engine and ignored by the two objective sections; an empty campaign ceiling was
fail-closed for the engine and fail-open for those same sections; the creatives endpoint of the same
token never read the account axis at all; and the attribution section was bounded by nothing. Each
was measured on real data before it was called a defect, and the browser caught one the whole test
suite had passed over — `show()` and `live()` each holding their own copy of the section flags.

**Two mistakes worth carrying forward.** A flag written as a PHP array union (`+` keeps the LEFT
operand) would have switched a section ON for every link that never asked for it — the opposite of
the leak being fixed, caught by re-reading the patch and not by any test. And a guard that read
`best`/`worst` where the payload publishes `strongest`/`weakest` passed against keys that do not
exist. Both say the same thing: a green test proves the assertion ran, not that it asserted anything.

## 2026-09-09 — where this stopped

`origin/main` = `a3cbff33` (#327), deployed. #320 through #327 all merged and deployed in this run;
`docs/ACTIVE_EXECUTION_STATE.md` carries the detail and the evidence table.

**Production-verified:** #320 (the outline reports `performance: present: true` over
`totals.spend = 10,696.54`, and the narrative sections no longer claim the figures were examined)
and #322 (the ads section declares the money it leads with). Also measured on Production: the public
share link's detail tables, in Arabic RTL at 1440 and 375 — zero drift, zero alignment mismatch, all
tabular numerals, the page not scrolling sideways while the tables do.

**Deployed but NOT verified: #321.** Its endpoints are authenticated `/app` surfaces and the browser
pane renders those at `docW: 0`. Local proof only. Not marked VERIFIED, and it should not be.

**The run's real subject was a guard that could not fail.** `table-alignment-sweep.spec.ts` measured
the distance between a numeric header's BOX centre and its cells' BOX centre. A `th` and the cells
beneath it share one table column, so those centres coincide by construction — the number was zero
whatever the text inside did. That is why «sixty-eight numeric columns, zero drifting» stood beside a
recorded injection of exactly the right shape PASSING on all three browsers, which this ledger
explained away as «no qualifying surface». With a real alignment check it found the owner's
five-times-reported defect on `/app/content` immediately, then the root cause of the whole class:
`.tnum` carries `direction: ltr`, so on a `<td>` it makes the cell an LTR box whose `text-end`
resolves opposite to its header's. 37 cells across 14 files, and a source guard that cannot let it
back.

**Two families this document had stopped naming, which is how a requirement leaves a ledger without
being deleted from it.** `GOVERNANCE-ANTILOSS-001` now asserts that every registered family appears
here, and its first run found both:

  - **`CLIENT-FACING-PRESENTATION-001`** — PARTIAL. The nine blocks are complete on the LIVE link
    only. The dashboard and snapshot forms have not been re-composed, and neither has the PDF.
  - **`EMAIL-DASHBOARD-UX-001`** — PARTIAL. The surface is built; what is missing is a real
    scheduled send observed end to end, which is MAIL-SEND's credential blocker rather than this
    row's own work.

**Read every «Remaining» clause against the code before acting on it.** Eight were checked this run
and eight were stale. One of them — `REPORT-DETAIL-PARITY-001`'s «ad, as a rung beneath its ad set» —
would have REINTRODUCED what the owner had explicitly removed («اسم واختيار الحملة احذفه من
التقارير»). The two accurate clauses each produced a real defect.

## 2026-09-08 — where this stopped (superseded above)

`origin/main` = `c51228b8` (#306), deployed. #303, #304, #305 and #306 all merged, deployed and
verified in Production this session. `docs/ACTIVE_EXECUTION_STATE.md` carries the detail.

**Closed on Production evidence:** owner rows 25, 26, 31, 32, 72, and the REPORT half of 3, 9 and 10.

**The defect the new gate found:** WebKit does not fire `seeked` under `preload="metadata"` without a
user gesture, so `VideoPoster` — which reported that event as proof of a painted frame — left every
coverless film as a blank card in Safari and on iOS. It reads `readyState` now. Fixed and deployed;
owner acceptance on the authenticated `/app/content` is still OPEN, so row 4 is
IMPLEMENTED_NOT_VERIFIED rather than verified.

**In flight:** branch `media/collection-shapes` — `integrations:probe --structure --shapes` reports
the key names a creative body carries, so the collection tile fetch (row 7) can be written against
Snapchat's real shape instead of a guess. Row 7 has a live subject: 434 collections on the live
account, 4 of them drawing nothing.

**Blocked, each on one thing only:** `/app/content` acceptance (no authenticated session, no failing
card named); the sandbox quarantine (AUTHORIZED, NOT EXECUTED — needs a VPS shell, safeguard stays);
Meta catalog re-mapping (`ads_management` not granted); the «henka» binding (`connection=error`).
Operational authority: `Git → REQUIREMENTS_TRACEABILITY_MATRIX.md → RESUME_STATE.md`.
This file owns **resumability**. The Matrix owns **requirements and status**; do not keep a second
copy of them here.

**This file had gone stale by sixteen merges** and described #248/#249 as open while main had reached
#264. A resume document that is wrong is worse than one that is missing, because the next session
plans from it. Repaired on 2026-09-04 from `git log origin/main`.

**Read this before you trust any row: a status is a claim about the PRODUCT, not about the code.**
On 2026-08-31 the owner opened the live client report and found four rows that said VERIFIED over
behaviour that was not there. Where this document and the running product disagree, the product is
right, and the row is downgraded rather than defended. `PRODUCTION-TRUTH-AUDIT-001` carries that
obligation and is not closed.

---

## 1. Where the tree is

```
origin/main = 711a5f6928af3b14cd9a25d6118b980d73c37688   (#302 Ads and Content named for what they are)
production  = https://campaignshub.io/   (200 after every deploy; the VPS resets hard to origin/main on push)
open PRs    = none at the time of writing
```

Reference share used for browser acceptance: the live client report the owner opened. **Do not
hard-code its token into product logic or fixtures** — read it in a browser, then discard it.

## 2. The active unit

`fix/content-is-written-in-contents-words` — the tab was renamed «أداء المحتويات» / «Content
performance» in #302 and everything INSIDE it kept the old vocabulary: panel «أداء الإعلانات», first
column «الإعلان», description «from ad-level data», empty state «No ad was reported». Same defect as
the rename, one level down, and worse here because these figures are `creative_daily_metrics` at the
CONTENT grain — one creative can be carried by several ads.

### Merged and DEPLOYED since #296, each verified on the running product

| PR | what it was | Production evidence |
|---|---|---|
| #299 `b233bea9` | `preview_url` is Meta's `preview_shareable_link`; six of twelve Meta cards fetched `fb.me` and drew HTML | Meta **12 usable / 0 unusable**, Snapchat **9 / 0** |
| #298 `1c1490c0` | «237.90 undefined»; counts written at full width | client report clean; funnel with exact figures behind it |
| #300 `ecef0bd0` | three more surfaces wrote counts raw; a funnel ratio claimed to be a conversion | funnel reads «165% ⚠» with its explanation; zero full-width counts |
| #301 `ce055c71` | a run left «running» since 2026-08-26 by a vanished worker | the stale-run guard no longer fires; a forced sweep queued and finished |
| #302 `711a5f69` | «الإعلانات» and «الإعلان» side by side; Content restored under its own name | AWAITING the owner — both surfaces are authenticated |

**Snapchat story ads have their covers.** A COMPOSITE carries no `top_snap_media_id`; it names its
children. After a forced sweep (`success records=11852`) the three highest-spending ads on the client
report — all composites, all previously «the platform exposed no asset» — now render an image or say
«فيديو — لم ترسل المنصة صورة غلاف له» and play.

### The blocker, and exactly what it blocks

`(#200) Ad account owner has NOT grant ads_management or ads_read permission` on the bound Meta
account, which synced `records=58` earlier the same day. It blocks ONLY re-mapping six Meta catalog
rows from `image` to `catalog`; those rows already draw a real thumbnail rather than a web page, so
nothing is blank while it waits. Registered as ledger row 67 against `INTEGRATION-META-001`.

### The register, and where it stands

`docs/OWNER_OBSERVED_DEFECTS.md` — 71 owner-observed defects, every one mapped to a requirement ID
that already existed. **3 are closed on Production evidence**; the rest are open, and the file's own
rule is that only Production closes one. `OwnerDefectLedgerTest` holds three invariants: a cited ID
must exist in the Matrix, a row may not claim verified while it still names a gap, and the count may
grow but never shrink.

Matrix, parsed from the file rather than remembered:
`VERIFIED 458 · PARTIAL 26 · IMPLEMENTED_NOT_VERIFIED 24 · IN_PROGRESS 17 ·
BLOCKED_EXTERNAL_CREDENTIALS 17 · BLOCKED_OPERATIONAL_EVIDENCE 11`.

### What no probe of mine can close

`/app/content` and `/agency/analytics` are authenticated and this session has no session on them.
Every media row and the Ads/Content naming stay open until the owner sees them. A probe from the
datacentre is not the owner's screen, and the ledger does not accept one for the other.

## 3. What binds, and where it is written down

Three requirement packages arrived on 2026-08-30/31 and are **all still binding**. They are recorded
in the Matrix — §56 registers what had no owner, and fifteen existing rows were extended in place.
None of it lives in a chat window any more.

* **Product corrections and analytical depth** — `TABLE-PRESENTATION-CONTRACT-001`,
  `ADSET-METRICS-TRUTH-001`, `ANALYTICS-DIFFERENTIATION-001`, `INSUFFICIENT-DATA-EXPLAINED-001`,
  `STORE-TABLE-PRESENTATION-001`, `BUDGET-ALERT-EMAIL-001`, `MONEY-SCOPE-TRUTH-001`, plus the
  extensions on `UX-KPI-PRESENTATION-001`, `NUMBER-PRESENTATION-001`, `OBJECTIVE-ANALYTICS-DEPTH-001`,
  `BUDGET-GOVERNANCE-001`, `PLATFORM-DECISION-ANALYTICS-001`, `DATA-QUALITY-OPERATOR-UX-001`,
  `CROSS-PLATFORM-ATTRIBUTION-DEPTH-001`.
* **Content** — `CONTENT-TERMINOLOGY-001` (the customer-facing experience is «المحتويات» / Content;
  the technical Ad entity is not renamed), `CONTENT-PREVIEW-SHAPES-001`, `CONTENT-DETAIL-MODAL-001`.
* **Reports, live share and branding** — `REPORT-PRODUCT-MODEL-001` (LIVE × Dashboard, LIVE ×
  Detailed Report, SNAPSHOT × Executive Summary, SNAPSHOT × Detailed Report),
  `REPORT-DETAIL-PARITY-001`, `REPORT-CREATION-UX-001`, `REPORT-INTERACTION-PARITY-001`,
  `BRANDING-RENDER-EVIDENCE-001`, and the reopened `REPORT-AD-PREVIEW-001` and
  `BRANDING-HIERARCHY-001`.
* **Campaign, lead and team operations** — `LEAD-OPERATIONS-001`, `TEAM-PROJECT-RBAC-001`,
  `EXECUTIVE-DAILY-DIGEST-001`, `LEAD-SOURCE-ATTRIBUTION-001`, `CAMPAIGN-OUTCOME-DIMENSION-001`,
  `LEAD-SLA-NOTIFICATION-001`, `EXECUTIVE-OPS-DASHBOARD-001`,
  `WHATSAPP-CONVERSATION-SOURCE-001`.
* **Governance** — `GOVERNANCE-ANTILOSS-001`, `PRODUCTION-TRUTH-AUDIT-001`.

## 4. Dependency order, so a cold session does not start in the wrong place

```
MONEY-SCOPE-TRUTH-001 ─┬─→ REPORT-AD-PREVIEW-001 ─→ REPORT-INTERACTION-PARITY-001
CONTENT-PREVIEW-SHAPES-001 ─┴─→ CONTENT-DETAIL-MODAL-001
TABLE-PRESENTATION-CONTRACT-001 ─→ OBJECTIVE-ANALYTICS-DEPTH-001 · STORE-TABLE-PRESENTATION-001
                                 · DATA-QUALITY-OPERATOR-UX-001 · ADSET-METRICS-TRUTH-001 (presentation half)
REPORT-PRODUCT-MODEL-001 ─→ REPORT-DETAIL-PARITY-001 ─→ REPORT-CREATION-UX-001
BRANDING-HIERARCHY-001 ─→ BRANDING-RENDER-EVIDENCE-001
TEAM-PROJECT-RBAC-001 ─→ LEAD-OPERATIONS-001 ─→ LEAD-SLA-NOTIFICATION-001 ─→ EXECUTIVE-OPS-DASHBOARD-001
                                             └─→ EXECUTIVE-DAILY-DIGEST-001
LEAD-SOURCE-ATTRIBUTION-001 runs beside LEAD-OPERATIONS-001; neither may invent identity from a click.
```

`ADSET-METRICS-TRUTH-001` is a pipeline fix and does not wait for the table contract; only its
presentation half does.

## 5. How to run it

```bash
# backend suite — a throwaway database, because the suite migrates
DB=ch_$(date +%s); createdb $DB
cd backend && DB_DATABASE=$DB DB_USERNAME=$(whoami) DB_PASSWORD="" php artisan test
./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G

# frontend
cd frontend && npm run typecheck && npx vitest run && npm run lint

# the browser gate — check the ports first, four checkouts share them
lsof -ti:8100,5273,5373 | xargs kill -9 2>/dev/null; npm run gate
```

Lanes live under `~/Developer/CampaignsHub-lanes/*`, each a git worktree of this repository.

## 6. Truthful blockers

* `WHATSAPP-CONVERSATION-SOURCE-001` — needs the WhatsApp Business Platform authorisation. Meta's
  click-to-WhatsApp ad metrics are a DIFFERENT source and may not stand in for conversations.
* `INTEGRATION-TIKTOK-001` — provider approval pending. Not VERIFIED, and it blocks nothing else.
* `MAIL-SEND` — no live SMTP credential; composition and ledgers are proven, delivery is not.
* Several `BLOCKED_OPERATIONAL_EVIDENCE` rows need observation on Production, not code.

Never request a secret in chat or in a GitHub issue.

## 7. Standing decisions

```
Theme     dark = default/reference; light = explicit and remembered; never system-driven
Numerals  Latin by default; language never switches numerals
Money     subscriptions USD, reporting SAR, the original currency always kept; totals fail closed
          on a partial or mixed scope, and a figure carries the currency it was measured in or none
Metrics   one canonical model — no per-page objective maps, money rules or currency logic
Engines   extend what exists. No second CRM, RBAC, scheduler, mail, media or reporting engine.
```


## 2026-09-08, later — final state of this session

`origin/main` = `f348306e`, deployed and verified. Eight PRs merged and deployed:
#303 #304 #305 #306 #307 #309 #310 #308.

Closed on Production evidence: owner rows 25, 26, 31, 32, 72, the report half of 3, 9 and 10,
and `CREATIVE-AD-RELATION-001` (1,524 creatives reachable through the canonical relation).

Corrected rather than carried forward:
- `TABLE-NUMERIC-ALIGNMENT-001` — its recorded next step («the SEED») was false. The seed has
  figures; the content list has no PURE-NUMERAL column by design, so no seeding can satisfy that
  sweep. Status stays PARTIAL: VERIFIED needs Production observation of an authenticated table.
- `CLIENT-DIAGNOSTIC-SEPARATION-001` — the PDF gap is auth-blocked, not code: `/reports/print/`
  refuses a live share token by design.
- `GATE-WK-001` — reconciled to VERIFIED on `47c9ef9`; it had held two statuses at once.

Two process traps that nearly produced false reports, both now guarded by habit:
a dispatch can FAIL while the script says «dispatched» (compare run ids before and after), and a
merge can SUCCEED while the API call times out (check `state`/`mergeCommit`, never blind-retry).

`docs/ACTIVE_EXECUTION_STATE.md` carries the full blocked list. Nothing independently executable
remains open; every remaining item names exactly one external dependency.


## 2026-09-09 — end of the autonomous stretch

`origin/main` = `8316d3a7`, deployed and Production-verified. Fifteen PRs merged and deployed.

**The sandbox phantom is CLOSED.** The client report reads `state: complete`,
`expected: [meta, linkedin, snapchat]`, `excluded: []`, with totals unchanged at 9,842.78 / 566 —
so nothing real was hidden to achieve it. It took four attempts; #313 and #315 deployed and did
NOT work, and both are recorded as such rather than as fixes. The cause of three wrong guesses was
that every diagnostic was account-scoped while coverage is project-scoped.

**Also closed on Production evidence:** owner row 2 (Meta media 12/0/0) and row 3's server half.

**In flight on `queue/multiselect-and-terminology`:** the report-scope picker's entity axes moved to
the shared searchable multiselect (owner row 47), and a terminology collision fixed — the creatives
axis was labelled «Ads» / «الإعلانات», identical to the ads axis, in both languages.

**Read `docs/ACTIVE_EXECUTION_STATE.md`** for the full blocked list; every remaining item names one
external dependency — an authenticated session, a VPS shell, a provider credential, or an owner
decision.
