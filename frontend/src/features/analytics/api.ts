import { useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'

/** KPI bundle returned by every aggregation (base sums + derived ratios; nulls when undefined). */
export interface MetricTotals {
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

export interface Summary {
  current: MetricTotals
  previous: MetricTotals
  delta: Partial<Record<keyof MetricTotals, number | null>>
  commerce: CommerceSummary | null
  conversions_basis: ConversionsBasis
}
export interface TimePoint extends MetricTotals {
  date: string
}
export interface PlatformRow extends MetricTotals {
  provider: string
  spend_share: number
}
export interface CampaignRow extends MetricTotals {
  campaign_id: string
  campaign_name: string | null
  provider: string
}
export interface FunnelStage {
  stage: string
  label: string
  count: number
  step_rate: number | null
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
}
const qf = (f?: MetricFilters) =>
  (f?.provider?.length ? `&provider=${f.provider.join(',')}` : '') +
  (f?.objective?.length ? `&objective=${f.objective.join(',')}` : '')

function useMetric<T>(key: string, projectId: string | null, range: Range, path: string, filters?: MetricFilters) {
  return useQuery({
    queryKey: ['metrics', key, projectId, range.from, range.to, filters?.provider?.join(',') ?? '', filters?.objective?.join(',') ?? ''],
    queryFn: () => getData<T>(`${base(projectId!)}/${path}?${q(range)}${qf(filters)}`),
    enabled: Boolean(projectId),
  })
}

export const useSummary = (p: string | null, r: Range, f?: MetricFilters) => useMetric<Summary>('summary', p, r, 'summary', f)
export const useTimeseries = (p: string | null, r: Range, f?: MetricFilters) => useMetric<TimePoint[]>('timeseries', p, r, 'timeseries', f)
export const usePlatforms = (p: string | null, r: Range, f?: MetricFilters) => useMetric<PlatformRow[]>('platforms', p, r, 'platforms', f)
export const useCampaigns = (p: string | null, r: Range, f?: MetricFilters) => useMetric<CampaignRow[]>('campaigns', p, r, 'campaigns', f)
export const useFunnel = (p: string | null, r: Range, f?: MetricFilters) => useMetric<FunnelStage[]>('funnel', p, r, 'funnel', f)
export const useBudget = (p: string | null, r: Range, f?: MetricFilters) => useMetric<BudgetRow[]>('budget', p, r, 'budget', f)
export const useFreshness = (p: string | null, r: Range, f?: MetricFilters) => useMetric<FreshnessRow[]>('freshness', p, r, 'freshness', f)
export const useNormalization = (p: string | null, r: Range, f?: MetricFilters) => useMetric<Normalization>('normalization', p, r, 'normalization', f)
export const useAttribution = (p: string | null, r: Range, f?: MetricFilters) => useMetric<Attribution>('attribution', p, r, 'attribution', f)
