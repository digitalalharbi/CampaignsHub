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

export interface Summary {
  current: MetricTotals
  previous: MetricTotals
  delta: Partial<Record<keyof MetricTotals, number | null>>
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
export interface FreshnessRow {
  provider: string
  latest_metric_date: string | null
  data_freshness_at: string | null
  days_with_data: number
  missing_days: number
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
