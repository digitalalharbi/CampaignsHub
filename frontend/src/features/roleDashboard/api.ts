import type { FunnelStageRow } from '@/features/analytics/charts'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
import type { CreativePreview } from '@/features/content/api'
import type { FxConversion, FxSpendNote } from '@/lib/money/fx'

/**
 * ROLE-DASHBOARD-001 — `GET /api/v1/me/dashboard`: the landing page's facts for the signed-in role, already narrowed on the
 * server to the clients and sections the member reaches. A section the member lacks arrives as `null` (never as zeros).
 */
export type DashboardView = 'manager' | 'pm' | 'finance' | 'marketer'

export interface BudgetReading {
  budget: number | null
  spent: number | null
  remaining: number | null
  projected: number | null
  pace: number | null
  currency: string | null
  currencies: number
  excluded: number
  unmeasured: number
  campaigns: number
}

export interface DashboardUnit extends BudgetReading {
  id: string
  kind: 'client' | 'project'
  name: string
  projects: { id: string; name: string }[]
  /** spent ÷ budget; null when either is not measured. */
  ratio: number | null
  /** Average daily spend of the last three complete days; null when spend is not measured. */
  daily_rate_3d: number | null
  days_left: number | null
  run_out_on: string | null
  /** DELIVERY-STATE-001 — spend AND impressions in the last 3 days on any platform. */
  delivering?: boolean
  /** provider → running in the last 3 days (a provider that did not spend is absent). */
  delivering_platforms?: Record<string, boolean>
}

/** The words for a figure travel with it, in both languages (`ObjectiveResult`). */
export interface Words { ar: string; en: string }

export interface CampaignRow {
  id: string
  project_id: string | null
  name: string
  status: string | null
  family: string
  platform: string
  spend: number | null
  result: number | null
  result_label: Words | null
  cost_per_result: number | null
  cost_label: Words | null
  roas: number | null
}

export interface RankedCampaign {
  id: string
  project_id: string | null
  name: string
  family: string
  /** WATCH-BOARD-001 — the platform it ran on and its spend in the window (null without the money key). */
  platform?: string
  spend?: number | null
  cost_per_result: number | null
  cost_label: Words | null
  result: number | null
  result_label: Words | null
  roas: number | null
}

export interface PlatformRow {
  platform: string
  spend: number | null
  share: number | null
  result: number | null
  result_label: Words | null
  cost_per_result: number | null
  cost_label: Words | null
  roas: number | null
  ctr: number | null
}

export interface CampaignCounts { active: number; paused: number; draft: number; completed: number; other: number; total: number }

export interface InvoicesBlock {
  currency: string | null
  mixed_currency: boolean
  invoiced: number
  collected: number
  owed: number
  overdue: number
  overdue_count: number
  due_soon_count: number
  by_status: Record<string, number>
}

export interface RoleDashboard {
  view: DashboardView
  default_view: DashboardView
  available_views: DashboardView[]
  window: { from: string; to: string; days: number; today: string }
  thresholds: { near: number }
  scope: { clients: number; projects: number; restricted: boolean }
  can_money: boolean
  /** DASH-METRICS-CURRENCY-001 — the currency the platforms' figures are summed in (not the budgets' SAR); null when mixed. */
  metrics_currency?: string | null
  /** WATCH-BOARD-001 — whether the follow-up board is offered (the member may open the campaigns). */
  can_watch?: boolean
  sections: string[]
  /** PROJECT-LIFECYCLE-001 §7 — the budgets page's budget (SAR); `budget_fx`/`spent_fx` = the same converted by the engine; `fx` = exclusions + old rates. */
  money: (BudgetReading & { rows: number; budget_fx?: FxConversion | null; spent_fx?: FxConversion | null; fx?: FxSpendNote; platform_campaign_budgets?: BudgetReading | null }) | null
  units: DashboardUnit[]
  daily_spend: { date: string; spend: number }[]
  alerts?: { open: number; critical: number; warning: number } | null
  integrations?: { ok: number; attention: number; pending: number; platforms: { platform: string; ok: number; attention: number }[] } | null
  campaigns?: CampaignCounts
  tasks?: { open: number; overdue: number; blocked: number; mine: number } | null
  approvals?: { pending: number; changes_requested: number } | null
  invoices?: InvoicesBlock | null
  mine?: boolean
  campaign_rows?: CampaignRow[]
  families?: { family: string; campaigns: number; spend: number | null }[]
  rank_family?: string
  best?: RankedCampaign[]
  worst?: RankedCampaign[]
  platforms?: PlatformRow[]
  /** DASH-PERIOD-001 — the window's headline over every campaign; spend against the window just before it. */
  performance?: PerformanceBlock | null
  /** DASH-PERF-SPLIT-001 — the manager's performance is read by `useDashboardPerformance`, not with the page. */
  performance_deferred?: boolean
}

/** DASH-PERF-SPLIT-001 — `GET /me/dashboard/performance`: the manager's performance panels, over every reachable campaign. */
export type DashboardPerformance = Pick<RoleDashboard, 'window' | 'metrics_currency' | 'campaign_rows' | 'families' | 'rank_family' | 'best' | 'worst' | 'platforms' | 'performance'>

export interface PerformanceBlock {
  spend: number | null
  previous_spend: number | null
  previous: { from: string; to: string }
  withheld: number
  campaigns: number
  family: string | null
  results: number | null
  result_label: Words | null
  cost_per_result: number | null
  cost_label: Words | null
}

/** DASH-PERIOD-001 — the windows the page offers (the server answers 30 to anything else). */
/** DASH-METRICS-CURRENCY-001 — the unit of a platform figure: the rows' own, or (from an older server) the budgets'. */
export function metricsCurrency(d: Pick<RoleDashboard, 'metrics_currency' | 'money'>): string | null {
  return d.metrics_currency !== undefined ? d.metrics_currency : (d.money?.currency ?? null)
}

export const DASHBOARD_WINDOWS = [7, 14, 30, 90] as const
export type DashboardWindow = (typeof DASHBOARD_WINDOWS)[number]
/** DASH-DATE-RANGE-001 — the period the page reads, from the shared date picker. */
export interface DashboardRange { from: string; to: string }
const setRange = (params: URLSearchParams, r: DashboardRange | null) => { if (r) { params.set('from', r.from); params.set('to', r.to) } }

export function useDashboardPerformance(range: DashboardRange | null, enabled = true, client: string | null = null, project: string | null = null) {
  // HOME-SCOPE-ISOLATION-001 — Owner 2026-11-20: these panels read every client while one was chosen ($23.5K, 1.83K orders,
  // other clients' best campaigns beside «Experts Live Saudi 2026»). They carry the chosen client and project now.
  const params = new URLSearchParams()
  setRange(params, range)
  if (client) params.set('client', client)
  if (project) params.set('project', project)
  const qs = params.toString()

  return useQuery({
    queryKey: ['me', 'dashboard', 'performance', range?.from, range?.to, client, project],
    queryFn: () => getData<DashboardPerformance>(qs ? `/me/dashboard/performance?${qs}` : '/me/dashboard/performance'),
    enabled,
    staleTime: 60_000,
    refetchInterval: 120_000,
    // Another window keeps the panels on screen until the new figures land.
    placeholderData: keepPreviousData,
    retry: false,
  })
}

export function useRoleDashboard(view: DashboardView | null, range: DashboardRange | null = null, client: string | null = null, project: string | null = null) {
  const params = new URLSearchParams()
  if (view) params.set('view', view)
  setRange(params, range)
  // DASH-CLIENT-FILTER-001 — the client chosen on the page narrows every figure; the server ignores one out of reach.
  if (client) params.set('client', client)
  // HOME-SCOPE-ISOLATION-001 — and the project chosen at the top, inside that client.
  if (project) params.set('project', project)
  const qs = params.toString()

  return useQuery({
    queryKey: ['me', 'dashboard', view, range?.from, range?.to, client, project],
    queryFn: () => getData<RoleDashboard>(qs ? `/me/dashboard?${qs}` : '/me/dashboard'),
    // A new window keeps the figures on screen until the new ones land, the same view's page in another period.
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    refetchInterval: 120_000,
    refetchOnWindowFocus: true,
    retry: false,
  })
}

/** WATCH-BOARD-001 — one content of the board: the ad as it ran, and the figures it is ranked on. */
export interface WatchContent {
  id: string | null
  name: string | null
  provider: string | null
  preview: CreativePreview | null
  spend: number | null
  impressions: number | null
  clicks: number | null
  conversions: number | null
  ctr: number | null
  cpa: number | null
  roas: number | null
}

export interface WatchUnit { id: string; kind: 'client' | 'project'; name: string; projects: number }

/** WATCH-BOARD-001 — `GET /me/dashboard/watch`: the follow-up board over the member's reach, or one client of it. */
export interface WatchBoard {
  window: RoleDashboard['window']
  units: WatchUnit[]
  unit: string | null
  can_money: boolean
  currency: string | null
  trend: { date: string; spend: number | null; impressions: number; clicks: number; conversions: number | null }[]
  content: {
    best: WatchContent[]
    worst: WatchContent[]
    basis: { metric: string | null; label_ar: string | null; label_en: string | null; family: string | null } | null
    in_scope: number
    /** HOME-CONTENT-REPORT-001 — the report's per-objective shelves. */
    groups?: { family: string; label_ar: string; label_en: string; metric: string | null; metric_label_ar: string | null; metric_label_en: string | null; ranked: boolean; total: number; ads: WatchContent[] }[]
  } | null
  /** HOME-CONTENT-REPORT-001 — the ad platforms' funnel over the scope. */
  funnel?: FunnelStageRow[]
  campaigns?: CampaignCounts
  campaign_rows?: CampaignRow[]
  families?: { family: string; campaigns: number; spend: number | null }[]
  rank_family?: string
  best?: RankedCampaign[]
  worst?: RankedCampaign[]
  platforms?: PlatformRow[]
  performance?: PerformanceBlock | null
}

export function useWatchBoard(range: DashboardRange | null, unit: string | null, enabled = true, project: string | null = null) {
  const params = new URLSearchParams()
  setRange(params, range)
  if (unit) params.set('unit', unit)
  if (project) params.set('project', project)
  const qs = params.toString()

  return useQuery({
    queryKey: ['me', 'dashboard', 'watch', range?.from, range?.to, unit, project],
    queryFn: () => getData<WatchBoard>(qs ? `/me/dashboard/watch?${qs}` : '/me/dashboard/watch'),
    enabled,
    staleTime: 60_000,
    refetchInterval: 120_000,
    // Another client or window keeps the board on screen, dimmed, until the new figures land.
    placeholderData: keepPreviousData,
    retry: false,
  })
}
