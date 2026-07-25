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

function useMetric<T>(key: string, projectId: string | null, range: Range, path: string) {
  return useQuery({
    queryKey: ['metrics', key, projectId, range.from, range.to],
    queryFn: () => getData<T>(`${base(projectId!)}/${path}?${q(range)}`),
    enabled: Boolean(projectId),
  })
}

export const useSummary = (p: string | null, r: Range) => useMetric<Summary>('summary', p, r, 'summary')
export const useTimeseries = (p: string | null, r: Range) => useMetric<TimePoint[]>('timeseries', p, r, 'timeseries')
export const usePlatforms = (p: string | null, r: Range) => useMetric<PlatformRow[]>('platforms', p, r, 'platforms')
export const useCampaigns = (p: string | null, r: Range) => useMetric<CampaignRow[]>('campaigns', p, r, 'campaigns')
export const useFunnel = (p: string | null, r: Range) => useMetric<FunnelStage[]>('funnel', p, r, 'funnel')
export const useBudget = (p: string | null, r: Range) => useMetric<BudgetRow[]>('budget', p, r, 'budget')
export const useFreshness = (p: string | null, r: Range) => useMetric<FreshnessRow[]>('freshness', p, r, 'freshness')
