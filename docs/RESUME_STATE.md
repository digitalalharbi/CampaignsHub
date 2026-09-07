# START HERE — 2026-09-08 (reconciled from Git after #306)

Read this file, then `docs/REQUIREMENTS_TRACEABILITY_MATRIX.md`, then `git log origin/main`.

## 2026-09-08 — where this stopped

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
