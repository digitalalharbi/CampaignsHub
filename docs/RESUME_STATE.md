# START HERE — 2026-08-25, consolidation in flight

Read this file and `git log origin/main` first. **Do not re-audit the whole project.**
Everything below is production evidence or a named local verification. Nothing here is inferred.

---

## 1. Current main

```
origin/main = 09fc4a0  (#95 merged and deployed 2026-08-24)
```

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

## 4. Open — one consolidated path

| PR | contains | state |
|---|---|---|
| #97 `release/consolidation` | #88, #92, #93, #96 merged cleanly, plus `CONTENT-TRUTH-001` and `METRICS-EMPTY-SCOPE-001` | backend ✅ frontend ✅ gate ⏳ |

Four PRs were each making the others stale at ~60 minutes per shuffle. They touch different areas
and now travel together.

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

## 7. Next session, in order

1. #97 gate → merge at exact head → deploy
2. Production browser verify: platform chips re-scope data, 12 Analytics tabs reachable, rail
   grouping, RTL/LTR, mobile/desktop, footer `mailto:`/`tel:` links
3. Re-run diagnose; confirm the video posters and the three Content empty states on real creatives
4. Decide what to do about `sbx-cmp-1` — it is the only remaining ad-stats refusal
