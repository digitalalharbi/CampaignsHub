import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { getData } from '@/lib/api/client'
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
  sections: string[]
  /** PROJECT-LIFECYCLE-001 §7 — the budgets page's budget (SAR); `budget_fx`/`spent_fx` = the same converted by the engine; `fx` = exclusions + old rates. */
  money: (BudgetReading & { rows: number; budget_fx?: FxConversion | null; spent_fx?: FxConversion | null; fx?: FxSpendNote }) | null
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
}

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
export const DASHBOARD_WINDOWS = [7, 14, 30, 90] as const
export type DashboardWindow = (typeof DASHBOARD_WINDOWS)[number]

export function useRoleDashboard(view: DashboardView | null, days: DashboardWindow = 30) {
  const params = new URLSearchParams()
  if (view) params.set('view', view)
  if (days !== 30) params.set('days', String(days))
  const qs = params.toString()

  return useQuery({
    queryKey: ['me', 'dashboard', view, days],
    queryFn: () => getData<RoleDashboard>(qs ? `/me/dashboard?${qs}` : '/me/dashboard'),
    // A new window keeps the figures on screen until the new ones land, the same view's page in another period.
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    refetchInterval: 120_000,
    refetchOnWindowFocus: true,
    retry: false,
  })
}
