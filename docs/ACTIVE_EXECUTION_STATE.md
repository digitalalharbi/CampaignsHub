# Active execution state

Single control plane. One line per unit. History lives in PR descriptions and commits, not here.

**Updated:** 2026-08-31 · **Deployed main:** `535155eb` (#237) · **Production:** 200, deploy green

> The repo requires branches to be UP TO DATE before merging (`strict_required_status_checks_policy`),
> so every open PR costs a full CI cycle: main moves, the rest go stale, each needs a rebase and a
> fresh run. Nine open PRs is nine cycles, not nine parallel ones. When the queue grows, consolidate
> — cherry-pick the units onto one branch and close the originals — and otherwise keep at most two
> open. This is the single biggest throughput lever in this repository.

## Active
```
(queue empty — #237 merged and deployed)
```

## Shipped 2026-08-30 → 31 — every one merged on a current head with all five checks green
```
#209 KPI cards: 4 more surfaces        #226 KPI cards: the last six + the row's own acceptance
#210 One vocabulary for a connection   #227 Presentation polish (multiplier · counted nouns ·
#211 The manage picker's locked rows         phone report list · absence-line placement)
#212 The card says what it is doing    #228 Wizard §4 · project page §11 §12 · confidence ·
#213 A press before hydration                report ads on all three surfaces
#214 Reconnect is a distinct action    #229 Path efficiency · family distribution
#215 Provider errors, four actors      #231 Budget: the other four steps
#216 One card per source               #232 The printed document consumes the outline
                                       #233 Per-path trend, with the unreported days
                                       #234 Attribution findings: which disagreement it is
                                       #235 Store funnel reads itself
                                       #236 The ads grid says what its ranking means
                                       #237 How far apart a path's platforms are
```

## Shipped
```
#109 Sortable analytics tables   78d5bfa   deploy ✅  index-BwCny18i.js
#110 Money-truth canonical       a06d5ee   deploy ✅  index-LQsLm74t.js
#111 Monthly digest rhythm       01c4808   deploy ✅  index-BFQ3gGLN.js
#112 Story-Ad creative cards     4837e56   deploy ✅  index-BpMnPrsG.js  (1 SSH retry)
```

## Absorbed from other sessions — all superseded, none deleted
```
fix/money-truth-canonical   = #110 + resolveMoneySeries on a pre-#110 base; would revert #109/#111.
                              Its unique piece is byte-identical to #117.          SUPERSEDED
fix/money-truth-002         36 behind; contract has 4 exports vs main's 8.          SUPERSEDED
fix/label-leaks             in main                                                SUPERSEDED
release/content-preview-money  in main                                             SUPERSEDED
feat/snap-creative-metrics-writer  main is ahead (4 vs 1)                          SUPERSEDED
fix/quality-tab-labels      shipped as #105; diff would delete 3,294 lines         SUPERSEDED
track/content               shipped as #112 via track/content-only                 SUPERSEDED
fix/creative-presenter-ads-backend  loadMissing('ads') present in main             SUPERSEDED
stash@{0}                   share-link fix shipped in #109                         SUPERSEDED
#107                        superseded by #110                                     CLOSED
#88 #92 #93 #96             verified per file, landed via #97                      CLOSED
```

## Blocked
```
Authenticated production UI   BLOCKED_OPERATIONAL_EVIDENCE  no operator session; the strongest
                              evidence available without one is: deploy green, production 200, and
                              `integrations:diagnose` read-only output (LinkedIn, 2026-08-30 20:26 UTC:
                              10 accounts, 1 bound, three consecutive sweeps storing 23 measured rows)
Meta/TikTok/Google/Zid/Salla  BLOCKED_EXTERNAL_CREDENTIALS  code complete, secrets absent
sla_warning withdrawal        needs the alert.type taxonomy migration (ALERT-SLA-UNRAISED-001)
```

## Required follow-ups — real work, not backlog
```
GAP-1  Analytics: Objectives, Accounts, Ad sets/Ads, Content render no table/chart/KPI/sort/compare
GAP-2  READY-4: ObjectiveTab asserts money_original_currencies:1; partial family prints half as total
GAP-3  Alerts missing: CTR/results decline · campaign stopped · sync STALE · attribution mismatch
       · creative fatigue as an alert · scaling opportunity
GAP-4  READY-3: ObjectiveTab duplicates metricCatalog's OBJECTIVE_LAYOUTS, weaker
GAP-5  NUMERAL-PREFERENCE-002: 52 remaining Intl.NumberFormat('en-US')/toFixed sites → lib/numerals
GAP-6  Email settings: recipients · weekly day · monthly day · thresholds · last/next send · log
GAP-7  Reports — PARTLY CLOSED 2026-08-31. The ads section now renders in the deck, the client's
       live link and the PDF, all from one builder (`ReportAds`), with the reading of its ranking
       (#228, #236). The printed document takes its sections, order and absent reasons from the
       report's own outline, and the live link carries an outline too (#232).
       STILL MISSING on the client-facing live link: objective split, attribution, best/worst
       campaign. Ad-SET detail is still absent from every report surface.
GAP-8  Dark-mode sweep after #116: InteractiveReport, CreativeViewer, modals, tables, charts, forms
```

## Standing decisions
```
Theme     dark = primary/default; light = explicit, remembered; prefers-color-scheme not consulted
Numerals  Latin default; language never switches numerals; explicit number_format=arabic must work
          in the authenticated UI; never applied to client shared reports, ids, ISO dates,
          currency codes, or server-rendered email
Money     totals fail closed on partial/mixed; charts drop-and-disclose; trends fail closed
Metrics   one canonical model — no per-page objective maps, money rules or currency logic
```

## Infrastructure note
```
Deploy SSH `dial tcp … i/o timeout` on 4 of 12 deploys (#102 #105 #108 #112). Each succeeded on
re-run. Not code. Watch, do not treat as flake-by-default.
```
