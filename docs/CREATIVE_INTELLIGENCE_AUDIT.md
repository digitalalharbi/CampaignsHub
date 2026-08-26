# Creative intelligence — what exists, and the three rankers that disagree

Read-only audit against `origin/main` at `266ab74`, by reading the tree. Implementation follows once
the conflicting PRs land; nothing here counts as delivered.

## 1. The finding: ranking is implemented three times, and they know different metrics

| ranker | metrics it can rank by |
|---|---|
| `Reports/Services/CreativeRankingService` (#114) | `spend roas cpa cpc cpm ctr leads` |
| `Campaigns/Services/CreativePulse` | `spend roas cpa cpm ctr impressions` |
| `Notifications/Services/DigestCreatives` | `spend roas cpa cpm ctr` |

Each carries its own `usort` and its own objective handling. The sets are not the same set:

- **`leads` exists in exactly one of the three.** The Pulse and the email digest cannot rank a
  lead-generation creative by lead volume at all, and none of the three can rank by `cpl`.
- `cpc` is missing from two; `impressions` exists in one.

So «best creative» is a different question depending on which screen asks it, and the same creative
can lead one list and be absent from another for reasons no operator can see. That is the concrete
form of the rule «no page-specific creative metric logic» being broken.

## 2. Direction-of-better is duplicated in at least four places

`LOWER_IS_BETTER` is re-declared, with different contents, in:

```
backend/  DigestPresenter.php            per-metric spec: cpc ✅ cpa ✅  (cpl, cpe, cpi, aov, engagement_rate ABSENT)
frontend/ CreativeQuickFacts.tsx         cpc cpm cpa cost_per_view cost_per_lpv
frontend/ CreativePulseSection.tsx       cpm cpc cpa cost_per_view cost_per_lpv
frontend/ CampaignComparison.tsx         its own notion again
```

A correction to my own first reading: I initially recorded that the digest treats CPC as
higher-is-better. It does not — `cpc` is marked `lower_is_better` in its spec table. That claim came
from a grep that surfaced only `['cpa']`, and it is wrong. What IS true is narrower and still real:
**`cpl`, `cpe`, `cpi`, `aov` and `engagement_rate` are absent from that spec entirely**, so a
lead-generation digest has no way to render its own primary KPI, and any metric that reaches the
renderer without a spec entry defaults to `lower_is_better => false`.

## 3. What #112 and #114 already satisfy — do not rebuild

| capability | state |
|---|---|
| Creative previews: image, video, poster, Story/carousel cards | ✅ `CreativeViewer`, `CreativeCarousel`, `CreativeVideoPlayer` (#112) |
| Worst-performing creatives beside best | ✅ `CreativeRankingService::worst()` + `weakness()` (#114) |
| Creative-grain metrics, availability, money provenance | ✅ `CreativeMetrics`, `CreativeMetricsAvailability`, `creativeMoney.ts` |
| No ad→creative projection | ✅ enforced; Snapchat alone implements `ReportsCreativeInsights` |
| Fatigue as evidence, not age | ✅ `CreativeFatigue`, surfaced as a finding |
| Shared-report creative section | ✅ `SharedCreativeSection`, `CreativeVisibility` (token-scoped media) |
| Creative funnel | ✅ `CreativeFunnel` exists — coverage unverified |

## 4. Gaps, in the order they cost a user something

1. **`CREATIVE-RANK-001` — one canonical ranker.** Collapse the three into a single contract that
   states `HIGHER_IS_BETTER` / `LOWER_IS_BETTER` explicitly per metric and takes the primary KPI from
   `metricCatalog`'s `layoutFor`, so best→worst is semantic order rather than numeric descending.
   Every consumer — Content, Pulse, digest, reports — reads it.
2. **`CREATIVE-RANK-002` — the digest metric spec is missing `cpl`, `cpe`, `cpi`, `aov`,
   `engagement_rate`.** Lead-gen and app-install projects cannot see their own primary KPI in email.
3. **`CREATIVE-CLIENT-001` — the client-facing report is the thin one.** `LiveSharedReport` carries
   platform, campaign, funnel and store; `InteractiveReport` carries creative, objective, attribution
   and best/worst. The report a client receives is the weaker of the two.
4. **`CREATIVE-LEAD-001` — lead-quality ranking modes.** Once lead provenance exists (LG-1/LG-2), a
   creative with cheap leads and poor qualification must not outrank an expensive one producing won
   business. Needs ranking modes: advertising performance · lead quality · business outcome.
5. **`CREATIVE-COMPARE-001` — cross-provider comparability.** Only Snapchat reports creative-grain
   metrics today, so this is latent rather than active; it becomes real the moment a second provider
   connects, and the rule (never silently mix definitions, windows or currencies) has to exist first.

## 5. Provider coverage — creative grain

`ReportsCreativeInsights` is implemented by **Snapchat alone**. That is a property of the providers,
not a gap: no other connector is asked for a grain its API does not return, and no ad figure is
projected downward to fill the space. Everything else is `BLOCKED_EXTERNAL_CREDENTIALS`, and nothing
about Snapchat's coverage may be read across to them.
