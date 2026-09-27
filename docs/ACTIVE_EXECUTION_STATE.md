# Active execution state

_Reconciled from Git on 2026-09-16. When this file and Git disagree, Git is right._

## BRANDING's Production clause, and the one action that unblocks it

`BRANDING-RENDER-EVIDENCE-001`'s bar is «the configured logo actually renders, proven per surface»,
and its own warning is that «code containing `logo_url` is not completion».

**Locally it is proven end to end.** `shared-report-branding.spec.ts` uploads a mark through the
authenticated Branding Center, then opens the client's link with NO session at all and asserts the
mark's decoded `naturalWidth` — not its visibility, because an `<img>` whose src 404s is still
«visible» and draws a broken-image icon, which on a client's report reads as «this report failed».

**On Production it cannot be proven yet, and the reason is not a defect.** Production PDF Acceptance
run `36291959238` of 2026-09-27 reports:

```
configured branding
  name               present
  logo               none configured
  logo_source        none
...
  "pages_with_images": 0,
```

Zero images in the PDF is the CORRECT outcome of that configuration: there is no mark to draw. The
measurement is already in place and would catch a mark that failed to render — `PdfFactsCommand`
prints what is configured precisely so that a measured logo count means something, and its docblock
says why.

**The single action that unblocks it:** upload a mark in Production's Branding Center, then re-run
Production PDF Acceptance. `logo` becomes `configured` and `pages_with_images` must become non-zero;
if it does not, that is the defect the row exists to catch. Until then this row's Production half is
`BLOCKED_OPERATIONAL_EVIDENCE` — a credential-gated upload, not an engineering gap.

## What landed on 2026-09-27, and how each one was found

Every defect in this block was found the same way: by **rendering the page and reading it**. None
of them failed a test, and none would have — each was a surface stating something untrue while
every assertion about its data passed.

| PR | Surface | What it said | What was true |
|---|---|---|---|
| #559 | `/agency/integrations` | chip «يحتاج اختيار حسابات» above body «هذه المنصة غير متاحة للربط حاليًا» | a stale wizard view outranked the platform state, telling a customer to do something impossible — on all six cards, with the same sentence three times per card |
| #560 | every notification | four of six `action_url`s named routes that **do not exist** | every one of those notifications ended on «الصفحة غير موجودة», for every reader, in both portals |
| #561 | `/agency/portfolio` | three cards reading «Q3 Launch — Demo» | the one view that crosses clients by definition did not name the client |
| #561 | `/agency/content` | the absence sentence printed twice per tile | `absenceLabel` already returns the server's note; the tile printed it again |
| #561 | `/portal/reports` | a bare `monthly` in an Arabic page | the type's Arabic label exists in a taxonomy the client's portal never asked |
| #561 | `/agency/dashboard` | one finding seven times | the loudest rule took all twelve slots and **evicted** the others, while `total` kept counting them |

### The pattern worth keeping

Five of the six are the same defect: **a surface printing a value where a sentence belongs**, or
printing one sentence twice. They are invisible to unit tests because the data is right — it is the
presentation that lies — and invisible to code review because each looks correct in isolation. The
only instrument that finds them is a rendered page and somebody reading it.

Three of the six also had the answer already written **one route over**: `/agency/projects` names
its client, `ClientCampaignsPage` translates its enums, `CampaignsPage` asks for a project. The
product disagreeing with itself is a stronger signal than any lint rule, and cheaper to check.

### What was NOT claimed

The Production confirmation of these six is **Owner-gated**. Every one is behind authentication,
`production-diagnostics.yml` accepts filters and never a command — deliberately, and correctly —
and this environment holds no Production URL or credential. Each is therefore
IMPLEMENTED + DEPLOYED with browser evidence on a local install, and its Production half is
`BLOCKED_OPERATIONAL_EVIDENCE` until somebody with a session opens the page.

## Where Git is (2026-09-16)

`origin/main` = **`312a7572` (#433)**. Eleven merges landed since `288bc48d` (#422): #423, #424,
#425, #426, #427, #428, #429, #430, #431, #433. **#432 is open** (`mkt-preload-heading-weight`,
`3c71f3c6`, `mergeStateStatus=BLOCKED`, `frontend` and `image` green, `backend` and the three
`gate` checks not reported). #432 is the Marketing cold-load lane and is not part of either closure.

## The execution model, changed by the Owner on 2026-09-16

The parent session no longer implements. It coordinates: it reads state, controls merge order and
records blockers. Product work happens in two isolated child workspaces, each with its own branch
and worktree cut from fresh `main`:

- **CONTENT CLOSURE — OWNER P0** · `owner/content-closure-p0` · Content only.
- **REPORTS CLOSURE — OWNER P0** · `owner/reports-closure-p0` · Reports only.

**WIP limit: one branch each, preferably one closure PR each.** No stream of micro-PRs. No shared
worktree, no shared branch, no cherry-picking between children. Merge order is Content, then
Reports rebased on fresh main and re-gated — one merge chain at a time, which matters because
auto-merge is disabled on this repository and every merge invalidates the other's «up to date».

## The queue that is neither child's

These stay in the Matrix and must not be pulled into a closure branch: Marketing cold-load
stability (#432 and MKT-FIX-001), Meta re-authorisation, Google OAuth, LinkedIn verification under
a currently-spending campaign, TikTok, X Ads, Salla/Zid, Dashboard / Campaigns / Analytics /
Budgets acceptance, Recommendations, Alerts, Tasks, Files, Settings, subscription verification,
and Production acceptance of the launch success experience.

## What the Owner's 2026-09-16 observation changed in the Matrix

`REPORT-PRODUCT-MODEL-001` read **VERIFIED**. The Owner reports that the modes render effectively
the same product, and a Production observation outranks a stale VERIFIED claim, so it now reads
**PARTIAL** with the observation recorded on the row. Two rows were added to the Owner defect
register — 95 (Content metric inconsistency and the missing previews) and 96 (report modes that do
not differ) — so that neither can disappear under the words «Content» or «Reports».

## Everything below is the earlier record, kept because its reasoning still applies

_Reconciled from Git on 2026-09-14. It had been left at #362 while `origin/main` reached #404 —
when this file and Git disagree, Git is right._

## Where Git is (2026-09-15)

`origin/main` = **`288bc48d` (#422)**.

**This file said `e5408a58` (#412). It was ten merges out of date** — the same failure its own 2026-09-14
section documents, repeated. Reconciled against Git rather than trusted, which is the owner's standing
instruction and the reason this section is rewritten rather than appended to.

Merged since #412: #413 … #420 (the Campaigns Command Center), #421 (the client's download and the
scope-dropping line), #422 (the Meta access probe, the provider error sentence that carries its
identifiers, and two ROAS panels that named the wrong campaign). Every one merged with six green
checks; #420 and #422 additionally verified against the served production bundle.

### The executable queue, from the owner's closure order

Phase D is **already built** and is PR #423 — the premium launch success experience, bound to the
server's own launch result, five checks green.

Phase A is where the work is, and the four streams are not equally executable:

- **A1 Content** — production evidence taken 2026-09-15 says the Snapchat chain is sound end to end
  (zero orphans, 936 rows a half hour, 24/24 cards drawing). One clause remains open and unproven:
  160 of 1526 creatives carry any figure. The provider-specific shapes that need credentials are
  recorded per clause, not as a blocked module.
- **A2 Reports** — the largest genuinely executable surface remaining.
- **A3 Integrations** — Meta and Google are `BLOCKED_EXTERNAL_CREDENTIALS` with dated production
  evidence; the Save-accounts defect and the `SQLSTATE[22001]` error-persistence contract are NOT
  blocked and are executable now.
- **A4 Marketing boot** — reopened by the owner; being re-measured on real cold loads against the
  deployed site rather than assumed closed from the last run's numbers.

### Why «141 not VERIFIED» is the honest count

The matrix holds 603 requirement rows. 457 read VERIFIED; the rest are 32 IMPLEMENTED_NOT_VERIFIED,
30 PARTIAL, 18 BLOCKED_EXTERNAL_CREDENTIALS, 16 IN_PROGRESS and 11 BLOCKED_OPERATIONAL_EVIDENCE.
That distribution is the queue, and it is read from the file rather than restated from memory.

## The earlier reconciliation of 2026-09-14, kept because its reasoning still applies

`origin/main` = `e5408a58` (#412).

**This file said #404. It was eight merges out of date — on the run whose own header explains that it
goes stale.** Reconciled against Git rather than trusted, which is the owner's standing instruction
and the reason this section is rewritten rather than appended to.

Merged since: #405 (ad-set grain) · #406 (what a share link may disclose) · #407 (one identity) ·
#408 (the live report's cross-platform comparison) · #409 (the detailed report's platform rung) ·
#410 (project lifecycle authorization) · #411 (one identity key) · #412 (fourteen commands that knew
how much they did). Every one merged with all six checks green and a confirmed main-branch deploy.

In flight: the owner's two latest corrections, tracked against **BRAND-MARK-001** (the identity, and
where clicking it goes) and **MKT-FIX-001** (the mobile marketing homepage shifting on a cold load).

### The earlier reconciliation, kept because its reasoning still applies

This run merged #402, #403 and #404, each with all six checks
green and each deploy confirmed against its own commit.

**#402** is verified in the served Production bundle. **#403** has no runtime surface — a test and a
ledger line — so its successful deploy is not dressed up as a verification. **#404** is deployed and
locally proven end to end (API figures, creative attribution by id, and the rendered client
document), but its Production half is BLOCKED_OPERATIONAL_EVIDENCE: every surface it touches is
reached through a share token, and minting a Production token would mean creating a client-facing
link on a live system.

**#405** (ad-set demo grain) is in CI. Behind it, prepared and guarded locally on
`ceiling-deploy-note`: the attribution unit — the last share-token endpoint with no ceiling at all,
plus the section flag the client page mounts on.

**Open, recorded honestly rather than closed:** `ADSET-METRICS-TRUTH-001` stays
IMPLEMENTED_NOT_VERIFIED, because #405 seeds demo data only and demo seeders never run in production;
the production half needs a real account on a provider that reports the grain
(PROVIDER-LIVE-VERIFICATION-001).


`origin/main` carries #355 · #356 · #357 · #358 · #359. Every one merged with all five checks green,
every main-branch run succeeded, and each deploy was confirmed by the production asset hash changing
— `index-CRSBNyzy` → `index-0LC4GDOC` → `index-B7S4T79-` → `index-DZYx8WFU`. `campaignshub.io`
answers 200 and `/api/v1/health` answers 200.

#360 (files library + client activity), #361 (budget export columns) and #362 (both Owner decisions)
followed. Asset hash across the run: `CRSBNyzy` → `0LC4GDOC` → `B7S4T79-` → `DZYx8WFU` → `DNBW_CaG`
→ `C4GtgTxb`. #361 left the hash unchanged, correctly — it was backend-only, so the bundle did not
rebuild. Production root and `/api/v1/health` both 200 throughout.

**PR #363 is open** on `ops-caps`: the sync-run summary, the Integration Centre's account log, and
two ledger clauses corrected against the code.

## The two Owner decisions, implemented

**Dashboard vs Analytics (#362).** Measured first: the two compositions were the same eight blocks
reordered, and both routes rendered the same twelve tabs. The depth IS the tabs, so the tab bar became
the analysis; the dashboard drops the spend curve, the rate trends and the store ledger, all of which
answer «why» and all of which the analysis already draws from the same rows. No second pipeline — the
split is composition only. The block ORDER was not touched, because the Owner had already fixed it
(never a diagnostic card above the KPI row; the reading of «why» last) and removal alone leaves exactly
that. A stale `?tab=` is carried to `/app/analytics` rather than dropped.

**Campaigns pagination (#362).** Paginating alone would have been worse than leaving it unbounded: the
server cuts by `created_at`, the browser re-orders twenty-five rows, and «the campaigns that need you»
silently becomes «the most relevant of the twenty-five newest». Relevance ordering moved server-side.
`CampaignRelevance` mirrors the TS rule and sorts rows `byCampaign()` already produced — no second
truth about a campaign's spend.

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

## Two things left for the Owner, not done unilaterally

**Analytics vs Dashboard.** Priority 5 asks for Analytics to be materially deeper than Dashboard. It
structurally cannot be: `ANALYTICS-AS-DASHBOARD-001` merged them into one board, and both routes
render the same component with the same twelve tabs and the same filters — only the FIRST TAB's
composition differs (`StoreLedger` on one, `DistributionBars` on the other). Either the requirement is
already satisfied (the depth exists and is reachable from both routes) or the two are to be
re-separated, which reverses a recorded decision and redesigns the product's two main screens.
Re-splitting the main workspace unprompted is not a call to make on the Owner's behalf.

**Campaigns pagination.** Written, then discarded rather than shipped half-done. The list endpoint is
unbounded and campaigns are the one list that grows without a ceiling, but the page's lifecycle
ORDERING depends on per-campaign metrics — so paginating would hide relevant campaigns behind page
boundaries, trading an over-fetch for a silent truncation. Doing it properly needs a `last_active_on`
subquery inside the structural query, which is the second analytics pipeline the architecture forbids.
The active/inactive split itself is status-only (`paused` / `completed` / `archived`), so it IS
tractable — as a designed unit, not a bolt-on.

## Two claims of mine that were wrong, and how

Both were inferred from a grep that found nothing, and both were caught before shipping. The lesson
is the same each time: **the absence of a string I guessed at is not the absence of the thing.**

- `ClientActivityController` looked unauthorized because it has no `abort_unless`. It calls
  `$this->access->assertView()`, which my pattern did not match.
- The ACCOUNT budget rung looked unrendered because nothing calls `useBudgetAccounts`. The component
  is `AccountBudgets`, it calls `useAccountBudgets`, and it has been wired into the Budget tab all
  along. I built a duplicate panel, hit «Duplicate function implementation» at typecheck, and
  reverted it. `useBudgetAccounts` is an unused twin of the live hook — dead code, left alone.

So the Owner's hierarchy is COMPLETE: Client → Project → Platform → Account → Campaign, all five
rungs rendered, the Project rung being #357's addition.

## Method notes worth keeping

- Two fixtures were corrected rather than the guards they broke, both describing conditions no sync
  produces: alert money rows with no `project_currency`, and a ranking fixture where the mean and the
  pooled figure happened to agree — which proved nothing until it was rebuilt to discriminate.
- The `/agency/team` webkit failure on #356 was environmental: the same spec passes locally on webkit,
  and the re-run went green. Reproduced before re-running, not assumed.
- A matrix note of mine carried literal pipes into a table cell. The width guard caught it, which is
  what it is for.
- **Two of my own guards passed under injection before they were right.** `toMatch(/5K SAR/)` against
  a table row matches the SPEND cell's «7.5K SAR», so blanking the column the assertion was about left
  it green; it asserts cell by cell now. And a first ranking fixture where the mean and the pooled
  figure happened to agree proved nothing until it was rebuilt to make them disagree.
- **I introduced a silent cap and caught it before merge.** Paginating tasks also bounded a card on the
  project integrations page that had been listing every task with no count — found by checking every
  consumer of the endpoint, not only the page the unit was about. It cost a CI cycle, which is the
  right trade.
- **The «eleven more literal caps» line was checked, and most of them were already honest.** Security
  events carry `history_total`/`history_withheld`, branding carries a total and a withheld count,
  invitations count what they left out, and the `DiagnoseSyncCommand` caps are deliberate CLI
  sampling rather than a list anybody reads as complete. The one that was genuinely silent was
  `CampaignAlertsController`: a hundred rows with no statement of how many the filter holds.
  Its `meta.counts` looked like coverage and is not — that is a status breakdown of every alert the
  campaign ever had, and it ignores the `status` filter the list was narrowed by, so a campaign with
  250 unread alerts asked for its unread ones returned a hundred rows beside «active: 250». Two true
  numbers, neither an answer to «is this list complete». It now carries `meta.total` and
  `meta.withheld` scoped to the filter, the hook reads them through `getEnvelope` instead of
  throwing `meta` away, and the tab says «تُعرض 100 من 250». Proved by injection: counting without
  the filter fails the one case that is about the filter.
- **And the NOTIFICATION CENTRE had the same silence**, which the first pass over this backlog line
  walked past because `meta.unread` looked like the endpoint already said something. An unread
  COUNT is not a statement of completeness: three hundred notifications with ninety unread showed a
  hundred rows beside «90», and nothing on screen said the other two hundred existed.
  `total` and `withheld` travel beside `unread` now, `listNotifications` reads them instead of
  taking `unread` and discarding the rest, and the dropdown says «تُعرض 100 من 300» only when
  something is actually held back. Built inline from the locale rather than by adding interpolation
  to `useT`, which takes a key and no values — a much larger blast radius than the defect.
  One defect, two routes, one unit: the campaign tab and the centre, each proved by injection.
- **Four more at two hundred, and a guard so the next one cannot ship silent.** Quotes, invoices,
  campaign annotations and Drive links all capped at 200 with no count. Invoices is the one that
  matters most — it is the list a customer reads to answer «have I been billed for everything», and
  a silently truncated one answers it wrongly in the direction that costs them nothing to believe.
  All four now count after their filters and before the bound.
  `BoundedListsStateTheirBoundTest` reads the controllers rather than testing each endpoint again,
  because what no feature test can say is «and the next one somebody writes will do this too» —
  which is exactly how this defect kept returning: security events were made honest, then campaign
  alerts shipped silent, then the notification centre, then these four, each written by somebody who
  had not read the others. Console sampling is deliberately out of scope: taking three payloads for
  a diagnostic is not a list anybody reads as complete.
- **And the consumers, because a count nobody renders is not a fix.** That is the same trap the
  report media defect turned on: the envelope existed and nothing read it. `listQuotes` and
  `listInvoices` used `getData`, which throws `meta` away, so the totals would have sat unread.
  **`InvoicesPage` was the serious one**: its summary cards — including «Outstanding», which is money
  somebody is owed — are reduced over the rows that ARRIVED, so past the bound they described the
  most recent two hundred invoices while wearing the label of the whole ledger, and understating a
  debt is the expensive direction. The page now says so above the cards. `PaymentsPage` distinguishes
  «nothing to pay» from «nothing to pay among the most recent shown», which are different sentences
  and only one is safe to show somebody who owes money. `TabBilling` filters one client out of a
  bounded fetch of every client's invoices, so a client whose invoices are older than the bound
  simply did not appear; it says that too. Computing these summaries server-side is the better
  answer and is a larger change — what could not wait is the claim.
