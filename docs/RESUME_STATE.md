# START HERE — 2026-08-26, money-truth consolidation in flight

Read this file and `git log origin/main` first. **Do not re-audit the whole project.**
Everything below is production evidence or a named local verification. Nothing here is inferred.

---

## 1. Current main

```
origin/main = 07c1a36  (#108 merged and deployed 2026-08-25)
production  = https://campaignshub.io/app  serving assets/index-B9N6TxdQ.js  ← matches 07c1a36
```

Everything from #97 through #108 landed after the section below was last written. The deploy ledger
is per-commit: #104 was **closed unmerged and never deployed** (superseded by #105), and no deploy
may be attributed to it.

| PR | merge SHA | deploy |
|---|---|---|
| #97 | `1e5636b` | success |
| #98 | `ad673a9` | success |
| #99 | `ce8d90e` | success |
| #100 | `61d3b20` | success |
| #101 | `aa2434a` | success |
| #102 | `e414956` | success |
| #103 | `f20483a` | success |
| #104 | — | **CLOSED, never merged, no deploy** |
| #105 | `3dc0a4e` | success (after one SSH i/o timeout, re-run) |
| #106 | `7147131` | success |
| #108 | `07c1a36` | success (after one SSH i/o timeout, re-run) |

The SSH `dial tcp … i/o timeout` on the deploy step has now recurred on three of eleven deploys, and
the GitHub API has been timing out against the same host. Each succeeded on re-run, so it is not
blocking — but it is an infrastructure signal, not noise.

## 2. Merged AND deployed, with the evidence that proved each

| PR | what it fixed | proof |
|---|---|---|
| #85 | `MKT-UGC-002` — the influencer/UGC card was **deleted** from the hero while the feature flag was off, so the service was invisible. Now announced, non-interactive, no route | browser on `campaignshub.io`: order `self-service · multi-client · services · soon-influencer`, `DIV`, `aria-disabled=true`, zero influencer hrefs |
| #89 | `CONTENT-KPI-AVAILABILITY-001` — headline selection became objective-aware **and** availability-aware | trace |
| #90 | `SNAP-AD-STATS-ROUTE-001` — blank parent ids never become `campaigns//stats`; the refusal now records its **path**; the creative sweep records received-vs-placed | named the real fault (below) |
| #91 | `CONTENT-KPI-SEMANTICS-001` — money answerability moved to **provenance**; `revenue_original = 0` no longer fabricates «0.00 USD» | trace: `card shows: spend, orders, conversion_rate, impressions` |
| #94 | `OBJECTIVE-NORMALIZATION-004` — Snapchat sends `WEB_CONVERSION` / `WEB_VIEW`; the map only knew `WEBSITE_CONVERSIONS` | migration: `75 examined, 71 reclassified, 4 still unclassified`; «other» **75 → 4** |
| #95 | `DIAGNOSE-LATEST-RECORDED-001` — a newer sweep with different keys silenced an older one's recorded refusal | two tests |

## 3. Production evidence, 2026-08-24

**Ad-stats refusal — NOT gone, and now named exactly:**

```
Snapchat Marketing API could not return ad stats: Request URL can not be
correctly processed  [path: /v1/campaigns/sbx-cmp-1/stats]
```

The URL is well-formed. `sbx-cmp-1` is a **sandbox test-fixture id** sitting under the live
Snapchat account. The account holds **89 campaigns and the sweep maps 88** — the refusal costs
exactly that one, and the sweep still wrote 173 ad-set and 1,176 ad rows. Removing that row is
production data surgery and has **not** been done.

**Creative coverage — the 35 classified:**

| bucket | count |
|---|---|
| A — provider returned no creative-grain row | **35** |
| B unmapped · C ambiguous · D write failure · E refusal | **0** |

507 rows received, 68 distinct ids sent, **68 placed, 0 unplaceable**. Snapchat reports these ads
and never names their creatives. No ad figure is projected downward.

**Platforms — every one checked, none inferred from Snapchat:**

| provider | production evidence |
|---|---|
| Snapchat | 309 accounts discovered, 1 ACTIVE binding, 89 campaigns, `daily_metrics` 1,848 rows latest today, 210 purchases, 4,689.09 USD spend withheld across 308 rows |
| Meta · TikTok · Google Ads · X · LinkedIn | **«No external account matches that filter»** — zero accounts discovered |

All six connectors implement `fetchInsights`. **Only Snapchat** implements
`ReportsCreativeInsights`, so creative-grain metrics are its alone by construction.

## 4. Open — reconciled 2026-08-26

`#97` merged as `1e5636b` and carried #88, #92, #93 and #96 with it. All four were verified landed
**per file** — not assumed from the consolidation note — and closed unmerged:

| PR | how it was checked | result |
|---|---|---|
| #96 | patch reverse-applies cleanly to `main` | both files already there byte-for-byte |
| #92 | blob comparison, all 6 files | `main == branch` |
| #93 | blob comparison, 14 files | 8 identical; 6 landed then `main` moved further via #100/#101/#106/#108 |
| #88 | describe-block present in `main` | landed, `main` since evolved past it |

Re-merging any of them would drag those forward-fixes backwards.

**#107 / #110 — one canonical money-truth result.** Both implemented `PARTIAL-WITHHELD-001` on
different bases and answered a `complete_converted` scope differently. Reconciled by diffing the
trees, not the descriptions: the backend model, Analytics, `InteractiveReport`, `CampaignComparison`
and `compareApi` were already byte-identical; `rankMoney` was deliberately superseded by
`rankableMoney` (drop-and-disclose); and `spendComparableAmount` — dropped by #110 as "duplicate or
weaker" — was **genuinely missing and restored**, because it is the only check that a converted
total is in the *budget's* currency. #107 is CLOSED unmerged; #110 is canonical.

| PR | contains | state |
|---|---|---|
| #110 `fix/money-truth-partial-withheld` | canonical money truth, head `59ecc3e` | gate ⏳ |
| #109 `track/reports` | sortable + squarely-aligned analytics tables, share-link origin | queued |
| #111 `feat/email-monthly` | monthly digest rhythm | queued |
| #112 `track/content-only` | Story-Ad cards in the creative viewer | queued |
| #113 `track/budget` | per-account budget ceilings | queued |
| #114 `track/report-depth` | worst-performing creatives | queued |

They ship strictly one at a time — fresh base, exact-head gate, pinned merge, deploy for that same
SHA, production verification — because two merges racing for `main` is how a deploy gets attributed
to the wrong one.

## 5. Three false negatives this instrument produced — read before trusting it

Each was one step from becoming a false report:

1. `nothing recorded — no sweep has run` — it read the **newest** run's meta; a structure sweep
   carries different keys, so a recorded **refusal went silent**. Fixed in #95.
2. `impressions (control) : 0` — `DailyMetric` carries a tenant global scope and `artisan` has no
   tenant, so the block matched nothing and read as «the production dashboard shows zero». It does
   not. Fixed in #96.
3. A watcher printed `SWEEP RECORDED` from an **empty error file**, because its check was a negative
   grep.

The shape is identical: **absence of evidence rendered as evidence of absence.** Any new check must
require a positive marker.

## 6. Blocked, precisely

- **A fresh production report** — reports are created through the authenticated API. The only report
  console commands are a scheduled dispatcher and a demo-export regenerator, and
  `production-diagnostics.yml` runs one fixed read-only command. Needs an operator session or a new
  sanctioned command. `BLOCKED_OPERATIONAL_EVIDENCE`.
- **Production browser verification of #97** — it is not deployed yet.

## 7. Next, in order

1. #110 gate → pinned merge → deploy same SHA → production verify
2. Then #109 → #111 → #112 → #113 → #114, each rebased onto the freshly deployed main
3. `READY-1` … `READY-4` from `INTEGRATION_READINESS_MATRIX.md` — the four real gaps that audit found
4. Decide what to do about `sbx-cmp-1` — still the only remaining ad-stats refusal

## 8. Integration readiness

`INTEGRATION_READINESS_MATRIX.md` (INTEG-READINESS-001) is the source of truth for what connecting a
provider now requires. The short version: **the foundation is built, not pending.** All eight
providers — Meta, TikTok, Google Ads, Snapchat, X, LinkedIn, Salla, Zid — have endpoints, scopes and
credential fields declared in config and read from the environment; OAuth carries PKCE and refresh;
webhook signatures are HMAC-SHA256 compared with `hash_equals`. Only Snapchat has ever met its
provider, and nothing about its success may be read across to the others.

`AD_PLATFORM_INTEGRATIONS_AUDIT.md` §2 is **superseded** — it describes a tree where every connector
was an awaiting-credentials stub, which would lead a reader to rebuild what exists.
