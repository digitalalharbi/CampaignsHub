import { useQuery } from '@tanstack/react-query'
import { api, ensureCsrfCookie, getData, postData, putData } from '@/lib/api/client'
import type { ActionState, CreativeOption, CreateAccountOption } from '@/features/campaigns/providerWrites'

/**
 * LAUNCH-001 — the launcher's contract with the server.
 *
 * A plan is what the builder asked for (`spec`) and what each platform answered (`steps`). Nothing in it is a
 * provider id until the provider said so.
 */
export interface LaunchProvider {
  provider: string
  objectives: string[]
  optimization_goals: string[]
  bid_strategies: string[]
  placement_families: string[]
  ad_set_refusal: string | null
  ad_refusal: string | null
  ad_fields: 'search_ad' | 'tiktok_ad' | 'creative'
  countries_in_ad_set: boolean
  bid_required: boolean
}

export type EventStatus = 'unavailable' | 'proven' | 'eligible'

/** LAUNCH-READINESS-001 — what an account was seen to measure, from its own synced results. */
export interface AccountReadiness {
  provider: string | null
  tier: 'sales_ready' | 'partial' | 'none'
  pixel: { status: 'unavailable'; reason: string }
  events: Record<'page_views' | 'add_to_cart' | 'leads' | 'purchases', { event: string; status: EventStatus; observed: number }>
}

export type ReadyAccountOption = CreateAccountOption & { readiness?: AccountReadiness | null }

export type BusinessGoal = 'sales' | 'leads' | 'awareness' | 'traffic'

/** LAUNCH-JOURNEY-001 — the objective one account has earned for a business goal. A proposal; nothing is saved by reading it. */
export interface StrategyAccount {
  account_id: string
  provider: string
  name: string | null
  tier: 'sales_ready' | 'partial' | 'none'
  objective_kind: string
  stage: 'sales' | 'leads' | 'awareness' | 'traffic' | 'bridge' | 'measurement_first'
  objective: string | null
  objective_refusal: string | null
  optimization_event: string | null
  reasons: string[]
  measurement_plan: Array<{ event: string; status: EventStatus; action: string }>
  advance: { next: string | null; needs: Array<{ event: string; observed: number; needed: number }> }
}

export interface LaunchOptions {
  accounts: ReadyAccountOption[]
  readiness: { window_days: number; eligible_events: number; project: { store_linked: boolean; ga4_linked: boolean; live_conversion_destinations: string[] } } | null
  providers: LaunchProvider[]
  creatives: Record<string, CreativeOption[]>
  launch_permitted: boolean
}

export interface LaunchSpec {
  name: string
  objective: string
  objectives?: Record<string, string>
  accounts: Array<{ external_account_id: string; daily_budget?: number | null }>
  ad_set: {
    name: string
    optimization_goal?: Record<string, string>
    daily_budget?: number | null
    countries?: string[]
    bid_amount?: Record<string, number>
    starts_at?: string | null
  } | null
  ad: {
    name: string
    creative_id?: Record<string, string>
    google?: { headlines: string[]; descriptions: string[] }
    tiktok?: { ad_text: string; call_to_action: string }
  } | null
  tracking: { url?: string; utm_source?: string; utm_medium?: string; utm_campaign?: string } | null
  launch: boolean
}

export type StepState = { status: 'pending' | 'done' | 'failed'; external_id?: string | null; entity_id?: string | null; error?: string | null; refusal?: string | null; request_id?: string | null }

export interface LaunchStep {
  account_id: string
  provider: string | null
  campaign: StepState
  ad_set: StepState | null
  ad: StepState | null
  launched: boolean | null
  launch_message?: string | null
}

export interface LaunchReviewRow {
  account_id: string
  provider: string
  account_name: string | null
  currency: string | null
  problems: string[]
  step: LaunchStep
}

export type PlanStatus = 'draft' | 'publishing' | 'published' | 'partial' | 'failed'

export interface LaunchPlan {
  id: string
  name: string
  objective: string
  status: PlanStatus
  launch: boolean
  unified_campaign_id: string | null
  spec: LaunchSpec
  steps: LaunchStep[]
  published_at: string | null
  created_at: string | null
  review?: LaunchReviewRow[]
}

const base = (projectId: string) => `/projects/${projectId}/launch`

export const useLaunchOptions = (projectId: string | null) => useQuery({
  queryKey: ['launch', 'options', projectId],
  queryFn: () => getData<LaunchOptions>(`${base(projectId!)}/options`),
  enabled: Boolean(projectId),
  retry: false,
})

export const useLaunchStrategy = (projectId: string | null, goal: BusinessGoal | null) => useQuery({
  queryKey: ['launch', 'strategy', projectId, goal],
  queryFn: () => getData<{ goal: BusinessGoal; accounts: StrategyAccount[] }>(`${base(projectId!)}/strategy?goal=${goal}`),
  enabled: Boolean(projectId) && goal !== null,
  retry: false,
})

export const useLaunchPlans = (projectId: string | null) => useQuery({
  queryKey: ['launch', 'plans', projectId],
  queryFn: () => getData<LaunchPlan[]>(`${base(projectId!)}/plans`),
  enabled: Boolean(projectId),
  retry: false,
})

export async function savePlan(projectId: string, spec: LaunchSpec, planId?: string | null): Promise<LaunchPlan> {
  await ensureCsrfCookie()
  return planId ? putData<LaunchPlan>(`${base(projectId)}/plans/${planId}`, spec) : postData<LaunchPlan>(`${base(projectId)}/plans`, spec)
}

/** 200 published, 207 partial, 422 failed — all three carry the plan, so none of them is thrown away. */
export async function publishPlan(projectId: string, planId: string): Promise<LaunchPlan> {
  await ensureCsrfCookie()
  const res = await api.post<{ data?: LaunchPlan; message?: string }>(`${base(projectId)}/plans/${planId}/publish`, { confirm: true }, { validateStatus: (s) => s === 200 || s === 207 || s === 422 })
  if (!res.data?.data) throw new Error(res.data?.message ?? 'The plan could not be published.')
  return res.data.data
}

export const accountState = (a: CreateAccountOption): ActionState => a.state
