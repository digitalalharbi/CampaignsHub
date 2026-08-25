import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/** KPI bundle returned by every aggregation (base sums + derived ratios; nulls when undefined). */
export interface MetricTotals extends MoneyProvenance {
  impressions: number
  clicks: number
  conversions: number
  spend: number
  revenue: number
  roas: number | null
  cpa: number | null
  ctr: number | null
  cpc: number | null
  cpm: number | null
  // DASH-010-D objective-specific base + derived metrics.
  reach: number
  video_views: number
  video_completions: number
  landing_page_views: number
  leads: number
  qualified_leads: number
  purchases: number
  installs: number
  registrations: number
  in_app_events: number
  engagements: number
  frequency: number | null
  cpl: number | null
  cpi: number | null
  cpe: number | null
  aov: number | null
  conversion_rate: number | null
  engagement_rate: number | null
  video_completion_rate: number | null
}

/**
 * UNIFIED-001 — the connected store's own figures, beside the platforms' rather than instead of them.
 *
 * `MetricTotals.revenue` is what the ad platforms' pixels believe their clicks caused. This is the
 * merchant's ledger. They are different numbers and both are worth having, so the dashboard labels
 * which is which — and never quietly replaces one with the other.
 *
 * The page's platform and objective filters do NOT narrow these figures — spend narrows to the chosen
 * platform and an order does not, and a large share of orders carry no attribution at all. So
 * `filtered_view` is set whenever the rest of the page is narrowed and this block is not, and the card
 * says so in words. The misreading it guards against is «this is Meta's revenue».
 */
export interface CommerceSummary {
  available: boolean
  filtered_view: boolean
  unfiltered_note_ar: string
  unfiltered_note_en: string
  orders: number
  revenue: number | null
  /**
   * COMMERCE-FX-001 — the currency these figures are in, and the orders missing from them.
   *
   * Store money is converted into the project's reporting currency at import; an order whose rate
   * could not be vouched for is withheld rather than added unconverted, so the revenue can be short
   * and the strip has to be able to say so.
   */
  reporting_currency: string
  orders_with_money_withheld: number
  money_withheld_currencies: string[]
  /**
   * COMMERCE-TZ-001 — the clock this window was measured on, and how many orders had their zone
   * ASSUMED rather than stated by the store. A day is a different sixty thousand seconds in every
   * timezone, so the report says which one it used.
   */
  reporting_timezone?: string
  orders_with_assumed_timezone?: number
  attributed_orders: number
  attributed_revenue: number | null
  unattributed_orders: number
  aov: number | null
  roas: number | null
  cac: number | null
  stores: number
  store_last_synced_at: string | null
}

/**
 * REPORT-OBJECTIVE-005 — what the single «conversions» figure is.
 *
 * It is the SUM of each platform's own claim, and those claims overlap: one sale clicked from two
 * platforms is reported in full by both, and no shared key exists that would prove they are the same
 * sale. `is_unique_order_count` is typed `false` rather than `boolean` — the server never sends true,
 * and a type that admitted it would let somebody write the branch that prints this as an order count.
 */
export interface ConversionsBasis {
  source: 'platform_reported'
  label_ar: string
  label_en: string
  providers: string[]
  may_double_count: boolean
  is_unique_order_count: false
  note_ar: string
  note_en: string
}

/**
 * MONEY-TRUTH-002 — provenance on breakdown rows.
 *
 * The backend's `byProvider` and `byCampaign` now carry the same withheld fields the summary does, so
 * a table row can tell «spent nothing» from «spent something we cannot convert». Declared here rather
 * than cast at the call site: a field that silently stops arriving should break the build.
 */
export interface MoneyProvenance {
  spend_withheld_rows?: number | null
  spend_original?: number | null
  revenue_withheld_rows?: number | null
  revenue_original?: number | null
  money_original_currency?: string | null
  money_original_currencies?: number | null
}

export interface Summary {
  current: MetricTotals
  previous: MetricTotals
  delta: Partial<Record<keyof MetricTotals, number | null>>
  /**
   * UX-METRICS-001 — which of `current`'s zeros are measurements.
   *
   * The base sums coalesce to 0, so a metric no platform reports and a metric reported as zero are
   * the same number here. `reported[key] === false` means nothing was ever sent, and the card must
   * say so instead of printing a zero. Base metrics only: derived ratios are computed rather than
   * sent, and their own `null` already says «there was no denominator».
   */
  reported: Record<string, boolean>
  /**
   * METRICS-EMPTY-SCOPE-001 — whether this SCOPE holds any row at all.
   *
   * `reported` answers «which metric keys are present», which is a question about the platform —
   * and over an empty scope it answers every key false, so the strip renders «لم ترسله المنصة»
   * under each one. Narrow the objective to a family this project never bought and the dashboard
   * states the platform sends no impressions: a claim about a connector, derived from an absence of
   * campaigns.
   *
   * An empty scope has no standing to say anything about a connector. When this is false the reader
   * says one true thing about the FILTER instead.
   */
  rows_in_scope: boolean
  /**
   * ANALYTICS-COMPARE-001 — whether the comparison window holds any rows to compare against.
   *
   * A delta is null both when a metric did not move off a base of zero and when there IS no previous
   * period. The cards rendered the same «— —» for each, under a heading promising a comparison. This
   * says which, so the page can state «no previous period» once rather than six mute dashes.
   */
  previous_rows_in_scope: boolean
  previous_range: { from: string; to: string }
  /**
   * HEADLINE-SCOPE-001 — the objective families the rows in scope actually belong to.
   *
   * «كل الأهداف» describes the filter, not the data. A project whose campaigns are all Sales has one
   * objective in scope either way, and the headline row follows this rather than the filter value.
   */
  objective_families_in_scope: string[]
  commerce: CommerceSummary | null
  conversions_basis: ConversionsBasis
  /**
   * MONEY-TRUTH-001 — the currency `current`'s converted money is expressed in.
   *
   * Null when the range holds no money rows, in which case there is no currency to name. Every money
   * surface reads this instead of assuming a market's currency.
   */
  currency: string | null
  /**
   * ANALYTICS-PROVENANCE-001 — whether these figures are real, seeded, or both.
   *
   * Derived by the backend from `is_demo` on the rows actually in scope. The frontend must not infer
   * it from the environment or a constant: neither knows whose rows these are.
   */
  provenance: { source: 'live' | 'demo' | 'mixed' | 'none'; live_rows: number; demo_rows: number }
}
export interface TimePoint extends MetricTotals {
  date: string
}
export interface PlatformRow extends MetricTotals, MoneyProvenance {
  provider: string
  spend_share: number
}
export interface CampaignRow extends MetricTotals {
  campaign_id: string
  campaign_name: string | null
  provider: string
  objective: string | null
  /**
   * ANALYTICS-OBJECTIVE-VISIBLE-001 — the canonical family, computed by the BACKEND.
   *
   * Never derived here. `CampaignObjective::family()` is the one mapping, and a copy in TypeScript
   * would drift the first time an objective is added — silently grouping a campaign under a verdict
   * it was never bought for.
   */
  objective_family: string | null
  objective_source: string | null
}
/**
 * One stage of the project (or campaign) funnel — FUNNEL-NULL-001.
 *
 * `count` is nullable and the null is load-bearing: it means no platform sent this key for the window,
 * which is a different fact from a platform that counted zero. `reported` states that distinction so
 * no caller has to infer it, and it is the field to branch on — `count === 0` is a real measurement.
 */
export interface FunnelStage {
  stage: string
  label: string
  /** True when at least one platform sent this key. False means never sent, and `count` is null. */
  reported: boolean
  count: number | null
  /** The stage this one's rate is measured against — the nearest reported step above, not the one
   *  above it in theory. Null on the first reported stage, and on an unreported one. */
  from_stage: string | null
  step_rate: number | null
  /**
   * FUNNEL-NOT-NESTED-001 — this stage counted MORE than the one above it.
   *
   * Production reports 3,048 checkouts against 1,806 add-to-carts. Both figures are real; the events
   * simply do not nest — a buy-now flow reaches checkout without an add-to-cart, and each event is
   * attributed on its own window. A funnel assumes every stage is a subset of the one above, and for
   * that pair the assumption is false.
   *
   * The screen used to print «166%» as a step and «-66%» as a drop-off, which is not a quantity.
   */
  exceeds_previous?: boolean
  /** Null when the stage exceeded the one above it: there was no drop to measure. */
  drop_off: number | null
  cost_per: number | null
}
export interface BudgetRow {
  campaign_id: string
  campaign_name: string
  status: string
  budget: number
  spent: number
  remaining: number
  consumed_pct: number | null
  pace: number | null
  projected_spend: number
}
/**
 * One source behind the project's figures — an ad platform OR a connected store (UNIFIED-001).
 *
 * Stores are on this list because they feed revenue, orders, AOV and ROAS. A freshness strip that
 * listed only the ad platforms was vouching for the numbers it had never checked.
 *
 * `days_with_data` and `missing_days` are null for a store: they count metric DAYS, and a shop does
 * not report a row per day. Null says «this question does not apply here», where a zero would say
 * «the shop reported nothing on any day of the window».
 */
export interface FreshnessRow {
  kind: 'ad_platform' | 'store'
  provider: string
  account_id: string | null
  name: string | null
  latest_metric_date: string | null
  data_freshness_at: string | null
  days_with_data: number | null
  missing_days: number | null
  /** `fresh` | `stale` | `failed` | `awaiting_credentials` — one vocabulary, every surface. */
  last_sync_status: string | null
  last_sync_at: string | null
  last_sync_error: string | null
}

/**
 * NORM-001 — the basis of the figures, not the figures.
 *
 * Every field here answers "what was done to this number before I saw it": which currency it arrived
 * in and what it was converted to, whose day boundary defines a day, which attribution window counted
 * the conversions, and whether the row came from a platform or from demo data.
 */
export interface CurrencyBasis {
  from: string
  to: string
  converted: boolean
  rows: number
  /**
   * Rows EXCLUDED from every money total in this window, because no rate for their date could be
   * vouched for (FX-001). `SUM` skips them, so a screen that did not say this would under-report and
   * look exactly like a complete total. Zero is the normal answer.
   */
  withheld: number
  rate_min: number | null
  rate_max: number | null
  latest_date: string | null
}
export interface TimezoneBasis {
  from: string
  to: string
  shifted: boolean
  rows: number
}
export interface MetricDefinitionRow {
  key: string
  name: string
  unit: string
  aggregation: string
  is_currency: boolean
  is_additive: boolean
}
export interface Normalization {
  project_currency: string | null
  project_currencies: string[]
  currencies: CurrencyBasis[]
  timezones: TimezoneBasis[]
  attribution_windows: Array<{ window: string; rows: number }>
  sources: Array<{ source_type: string; is_demo: boolean; rows: number }>
  objectives: {
    present: Array<{ objective: string; campaigns: number }>
    mixed: boolean
    comparable_metrics: string[]
    objective_specific_metrics: string[]
  }
  catalogue: { available: boolean; metrics: MetricDefinitionRow[] }
  unread_metric_keys: string[]
}

/**
 * REPORT-OBJECTIVE-005 — who is answering «كم بعنا؟».
 *
 * `total_orders` and `total_revenue` are typed `null` rather than `number | null`, on purpose. The
 * server never sends a unified platform total, and a type that admitted one would let a component be
 * written that renders it the day somebody changes the server — which is the whole defect. The type
 * is the second lock on the same rule.
 */
export interface AttributionWindowBasis {
  windows: Array<{ window: string; rows: number }>
  mixed_windows: boolean
  window_known: boolean
  click_through_days: number | null
  view_through_days: number | null
  includes_view_through: boolean | null
  unknown_ar: string | null
  unknown_en: string | null
}

export interface PlatformClaim {
  provider: string
  platform_reported_orders: number
  platform_reported_revenue: number
  store_confirmed_orders: number | null
  store_confirmed_revenue: number | null
  difference: number | null
  ratio: number | null
  attribution: AttributionWindowBasis
  currency: string | null
}

export interface DuplicatedShop {
  provider: string
  shop_external_id: string
  connections: number
  names: string[]
}

export interface Attribution {
  period: Range
  platform_reported: {
    label_ar: string
    label_en: string
    basis_ar: string
    basis_en: string
    platforms: PlatformClaim[]
    total_orders: null
    total_revenue: null
    total_withheld: boolean
    total_withheld_reason: string
    total_withheld_ar: string
    total_withheld_en: string
  }
  store_confirmed: {
    label_ar: string
    label_en: string
    available: boolean
    unavailable_reason: string | null
    unavailable_ar?: string
    unavailable_en?: string
    basis_ar?: string
    basis_en?: string
    orders: number | null
    revenue: number | null
    currency: string | null
    cancelled_orders?: number
    attributed_orders?: number
    duplicates_collapsed?: number
    shops_connected_more_than_once?: DuplicatedShop[]
  }
  dedup: {
    platform_reported: { status: string; reason_ar: string; reason_en: string; may_be_summed: boolean }
    store_confirmed: {
      status: string
      key: string | null
      reason_ar: string
      reason_en: string
      may_be_summed: boolean
      duplicates_collapsed?: number
    }
    comparison_ar: string
    comparison_en: string
    comparable_platforms: number
  }
  models: Array<{
    model: string
    is_set: boolean
    campaigns: number
    campaign_names: string[]
    windows: string[]
  }>
  unattributed: {
    available: boolean
    orders: number | null
    revenue: number | null
    share: number | null
    by_method: Array<{ method: string; orders: number }>
    note_ar?: string
    note_en?: string
  }
}

export interface Range {
  from: string
  to: string
}

/** Inclusive range of the last `days` days ending today, as YYYY-MM-DD. */
export function lastNDays(days: number): Range {
  const to = new Date()
  const from = new Date()
  from.setDate(to.getDate() - (days - 1))
  const fmt = (d: Date) => d.toISOString().slice(0, 10)
  return { from: fmt(from), to: fmt(to) }
}

const q = (r: Range) => `from=${r.from}&to=${r.to}`
const base = (projectId: string) => `/projects/${projectId}/metrics`

/** Dashboard command-center filters. Backend-supported (sent as query params) — never a React-only filter. */
export interface MetricFilters {
  provider?: string[]
  objective?: string[]
  /** UX-DASH-001 — the dashboard's campaign control. Absent means every campaign, never none. */
  campaign?: string[]
}
const qf = (f?: MetricFilters) =>
  (f?.provider?.length ? `&provider=${f.provider.join(',')}` : '') +
  (f?.objective?.length ? `&objective=${f.objective.join(',')}` : '') +
  (f?.campaign?.length ? `&campaign=${f.campaign.join(',')}` : '')

function useMetric<T>(key: string, projectId: string | null, range: Range, path: string, filters?: MetricFilters) {
  return useQuery({
    queryKey: [
      'metrics', key, projectId, range.from, range.to,
      filters?.provider?.join(',') ?? '',
      filters?.objective?.join(',') ?? '',
      // Part of the key, or the campaign filter would return whatever the unfiltered request cached.
      filters?.campaign?.join(',') ?? '',
    ],
    queryFn: () => getData<T>(`${base(projectId!)}/${path}?${q(range)}${qf(filters)}`),
    enabled: Boolean(projectId),
  })
}

/**
 * ANALYTICS-DRILLDOWN-001 — one row per ad squad or ad, from `entity_daily_metrics`.
 *
 * Extends `MoneyProvenance` for the same reason every other row shape does: the canonical money
 * reader keys off those field names, so an ad squad's withheld spend renders through the identical
 * code path as a dashboard KPI rather than a second implementation that agrees by luck.
 */
export interface EntityRow extends MoneyProvenance {
  entity_id: string
  external_id: string
  /** Null when the structure sweep has since removed the entity — a real state, not a placeholder. */
  name: string | null
  status: string | null
  campaign_id: string | null
  ad_set_id: string | null
  active_days: number
  last_active_on: string | null
  spend: number | null
  impressions: number | null
  reach: number | null
  frequency: number | null
  clicks: number | null
  landing_page_views: number | null
  engagements: number | null
  video_views: number | null
  video_p25: number | null
  video_p50: number | null
  video_p75: number | null
  video_p100: number | null
  video_watch_seconds: number | null
  conversions: number | null
  purchases: number | null
  leads: number | null
  installs: number | null
  revenue: number | null
  ctr: number | null
  cpc: number | null
  cpm: number | null
  cpa: number | null
  cpl: number | null
  cpi: number | null
  cpe: number | null
  cost_per_view: number | null
  cost_per_lpv: number | null
  roas: number | null
  aov: number | null
  conversion_rate: number | null
  engagement_rate: number | null
  completion_rate: number | null
  view_rate: number | null
}

export interface EntityPage {
  entities: EntityRow[]
  entity_type: string
  period: { from: string; to: string }
  currency: string | null
  attribution_window: string | null
}

/**
 * The ad-squad or ad level, optionally narrowed to one parent.
 *
 * `parent` is part of the query key AND the request: it changes the DATABASE scope, so a cached
 * unfiltered response must never be handed back for a drilled-down request.
 */
export const useEntities = (
  p: string | null,
  r: Range,
  level: 'ad_set' | 'ad',
  parent?: string | null,
  f?: MetricFilters,
) =>
  useQuery({
    queryKey: ['metrics', 'entities', level, p, r.from, r.to, parent ?? '', f?.provider?.join(',') ?? '', f?.objective?.join(',') ?? ''],
    queryFn: () =>
      getData<EntityPage>(
        `${base(p!)}/entities/${level}?${q(r)}${qf(f)}${parent === undefined || parent === null ? '' : `&parent=${encodeURIComponent(parent)}`}`,
      ),
    enabled: Boolean(p),
  })

export const useSummary = (p: string | null, r: Range, f?: MetricFilters) => useMetric<Summary>('summary', p, r, 'summary', f)
export const useTimeseries = (p: string | null, r: Range, f?: MetricFilters) => useMetric<TimePoint[]>('timeseries', p, r, 'timeseries', f)
export const usePlatforms = (p: string | null, r: Range, f?: MetricFilters) => useMetric<PlatformRow[]>('platforms', p, r, 'platforms', f)
export interface AccountRow extends MetricTotals {
  account_id: string | null
  provider: string
  /** Null when the account has been removed since these rows were ingested — its spend is still real. */
  account_name: string | null
}

/** ANALYTICS-DRILLDOWN-001 — the ad accounts beneath a platform. */
export const useAccounts = (p: string | null, r: Range, f?: MetricFilters) =>
  useMetric<AccountRow[]>('accounts', p, r, 'accounts', f)

export const useCampaigns = (p: string | null, r: Range, f?: MetricFilters) => useMetric<CampaignRow[]>('campaigns', p, r, 'campaigns', f)
export const useFunnel = (p: string | null, r: Range, f?: MetricFilters) => useMetric<FunnelStage[]>('funnel', p, r, 'funnel', f)
export const useBudget = (p: string | null, r: Range, f?: MetricFilters) => useMetric<BudgetRow[]>('budget', p, r, 'budget', f)
export const useFreshness = (p: string | null, r: Range, f?: MetricFilters) => useMetric<FreshnessRow[]>('freshness', p, r, 'freshness', f)
export const useNormalization = (p: string | null, r: Range, f?: MetricFilters) => useMetric<Normalization>('normalization', p, r, 'normalization', f)
export const useAttribution = (p: string | null, r: Range, f?: MetricFilters) => useMetric<Attribution>('attribution', p, r, 'attribution', f)
