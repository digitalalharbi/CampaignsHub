# Metric coverage audit — 2026-11-20 (read from Git at `df23aeac7`; no live provider credential was used)

Order: ingest every primary and secondary metric each official API provides, per platform, without assuming platforms match.
This is the inventory the batches work from. «Gap (available)» is from the provider's documented API and is **unverified until a
live read** (`PROVIDER-LIVE-VERIFICATION-001`).

## Where a metric lives

- `daily_metrics` (campaign grain): the 19 additive keys in `MetricsAggregator::PIVOT`; derived ratios (ctr, cpc, cpm, cpa, roas,
  cpl, cpi, cpe, aov, frequency, conversion_rate, engagement_rate, video_completion_rate) are computed at read time, never stored.
- `entity_daily_metrics` (ad set and ad grain): `UpsertEntityDailyMetrics::MEASURES`; adds frequency, video_views_2s/5s/15s,
  video_p25/p50/p75/p100, video_watch_seconds, sign_ups, app_opens, page_views. Only Snapchat, Meta, Google (and OpenAI Ads) report it.
- `measurement_daily_metrics`: GA4 only, never mixed with ad keys.
- Commerce (Salla, Zid) writes orders, products and customers, not canonical metric keys.

## A new key does not render by itself

connector field → `PIVOT` (campaign) or `MEASURES` + column + `SUMS` (entity) → `MetricDefinitionSeeder` → `metricCatalog.ts` `SPECS`
→ a layout slot → `METRIC_LABELS` (and `ENTITY_SERVED_METRICS` for entity tables). A key missing from `SPECS` is silently dropped.

## Gaps by platform (A = available in the official API, U = unverified)

| Group | Snapchat | TikTok | Meta | Google | X | LinkedIn |
|---|---|---|---|---|---|---|
| Likes, comments, shares, saves, follows | A shares, saves | A likes, comments, shares, follows | A reactions, comment, share, post_save | not offered | A likes, replies, retweets, follows | A likes, comments, shares, follows |
| Watch quartiles p25/p50/p75 | entity only | A | **entity done (this batch)**; campaign grain A | A (rates only) | A | A |
| Watch time | entity only | U | U (average only) | not offered | U | U |
| Product views | A, excluded on purpose | U | A, excluded on purpose | not offered | U | not offered |
| Add to cart, checkout | mapped | mapped | mapped | A (conversion_action_category) | mapped | not offered |
| Region, age, gender, device, placement | stored for region; rest A/U | region, age/gender stored | A | A | A | A |

Not mapped on purpose: LinkedIn `landingPageClicks` is a click, not a landing page view; Google entity `revenue` is all-conversion value
and can disagree with the campaign grain's purchase-only revenue (defect to schedule, not fixed here).

## Batches

1. **Done in this batch** — Meta ad-set and ad video quartiles (`video_p25/50/75/100`) into the existing `entity_daily_metrics`
   columns. No migration, no new key, no change to any existing figure. Tests: `MetaEntityGrainsTest` (+2).
2. Next, smallest first: Meta entity `frequency` (single-row rule), TikTok quartiles (campaign grain, new keys), Google ADD_TO_CART and
   BEGIN_CHECKOUT categories, then the label/layout slots so a stored key can be shown (`SPECS`, `METRIC_LABELS`).
