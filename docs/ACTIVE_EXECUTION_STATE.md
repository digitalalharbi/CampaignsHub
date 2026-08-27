# Active execution state

Single control plane. One line per unit. History lives in PR descriptions and commits, not here.

**Updated:** 2026-08-26 · **Deployed main:** `4837e56` (#112) · **Production:** `assets/index-BpMnPrsG.js`

## Active
```
#113 Budget accounts            CI_RUNNING   (run 32939951846)
#117 Money timeseries           CI_RUNNING   (run 32940444315)
#121 Numeral preference ph.1    CI_RUNNING   (run 32941518146)
#122 Gap audit (docs)           CI_RUNNING   (run 32941987701)
```
Merge and deploy stay sequential; parallel CI is fine because these share no files.

## Queue — merge order
```
#113 → #114 → #115 → #116 → #117 → #118 → #119 → #120 → #121 → #122
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
Authenticated production UI   BLOCKED_OPERATIONAL_EVIDENCE  no operator session
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
GAP-7  Reports — audited 2026-08-26, INVESTIGATION_REQUIRED resolved into concrete work:
       InteractiveReport  1258 ln  platform·campaign·creative·funnel·objective·attribution·best/worst  OK
       LiveSharedReport    521 ln  platform·campaign·funnel·store — MISSING creative, objective,
                                   attribution, best/worst. This is the CLIENT-facing shareable one.
       PublicReport        236 ln  broad coverage                                                  OK
       PrintDocument       224 ln  MISSING creative, store, best/worst
       No ad / ad-set detail in ANY report surface.
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
