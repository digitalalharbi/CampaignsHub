import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ApprovedBudget } from '@/features/funding/fundingApi'
import { deleteData, getEnvelope, patchData, postData } from '@/lib/api/client'

/**
 * BUDGET-GOVERNANCE-001 — the workspace's OWN limits, read from the endpoint that computes them.
 *
 * Nothing here derives a figure. Consumed, remaining, utilisation, pace, the projection and the
 * state are all computed server-side by `SpendLimitGovernor`, against the same money contract every
 * other surface reads — a second implementation in the browser would eventually disagree with the
 * alert that fires on the same threshold.
 */
export type SpendLimitScope = 'project' | 'platform' | 'account' | 'campaign'

export type SpendLimitState = 'ok' | 'approaching' | 'over' | 'unknown'

export interface SpendLimitReading {
  id: string
  scope: SpendLimitScope
  scope_id: string | null
  /** Always `internal_monitoring`. See the note the list endpoint sends beside it. */
  enforcement: string
  amount: number
  currency: string
  period: { from: string; to: string; days: number }
  elapsed_days: number
  /** BUDGET-PACING-COVERAGE-001 — the day the pace is measured through when it is not today; null means today. */
  paced_through?: string | null
  /** Null where the spend has no single figure — withheld, partial or in another currency. */
  consumed: number | null
  consumed_currency: string | null
  remaining: number | null
  utilisation: number | null
  pace: number | null
  projected_period_spend: number | null
  projected_exhaustion: { date: string | null; reason: string }
  thresholds: number[]
  state: SpendLimitState
  /** Why the figures are what they are: `comparable`, `partial`, `currency_mismatch`, … */
  basis: string
  /** BUDGET-FX-DEFAULT-001 — set when the spend was converted into the limit's currency (the rate, and where it came from). */
  converted?: { from: string; to: string; rate: number; source: string } | null
  /** BUDGET-RUNOUT-001 — the day the limit runs out at the last 3 full days' rate; `date` null with the reason. */
  run_out?: { date: string | null; reason: string; per_day: number | null }
  /** BUDGET-FRESHNESS-001 — a platform this limit rests on failed to sync, or its data is older than 2 hours. */
  freshness?: Freshness
  /** BUDGET-LIMITS-ACTIONS-001 — a campaign limit's platform campaigns and whether a pause may be ASKED for. */
  actions?: LimitAction[]
  /** ONE-BUDGET-001 — the approved budget this limit is a threshold of (null = the project has no budget yet). */
  project_funding_id?: string | null
  /** When the conversion linked it, and the row as it was before — read-only. */
  converted_at?: string | null
  legacy?: Record<string, unknown> | null
}

export interface FreshnessItem { provider: string; account: string | null; reason: 'failed' | 'access_lost' | 'old' | 'never'; error_category: string | null; last_synced_at: string | null }
export interface Freshness { stale: boolean; items: FreshnessItem[] }

export interface LimitAction {
  provider: string
  external_account_id: string
  target_external_id: string
  name: string | null
  status: string | null
  can_propose: boolean
  /** not_implemented | awaiting_credentials | no_permission | execution_not_enabled_for_provider | already_paused */
  why_not: string | null
}

export interface SpendLimitsPage {
  limits: SpendLimitReading[]
  enforcement_note_ar: string
  enforcement_note_en: string
  today: string
  /** The currency a new limit starts in: the project's own. */
  project_currency: string
  /** ONE-BUDGET-001 — the project's approved budget, the same reading as /agency/budgets, with its alert steps. */
  budget: ApprovedBudget | null
}

export function useSpendLimits(projectId: string | null) {
  return useQuery({
    queryKey: ['spend-limits', projectId],
    queryFn: async (): Promise<SpendLimitsPage> => {
      const envelope = await getEnvelope<SpendLimitReading[]>(`/projects/${projectId}/spend-limits`)
      const meta = envelope.meta as Partial<SpendLimitsPage> | undefined

      return {
        limits: envelope.data ?? [],
        /*
         * The sentence travels with the payload rather than being written here.
         *
         * It is the one thing a reader must not be able to lose: a limit nothing enforces, read as
         * one that does, means nobody goes and pauses the campaigns. A copy in the browser is a copy
         * that can drift from the one the API sends to everybody else.
         */
        enforcement_note_ar: meta?.enforcement_note_ar ?? '',
        enforcement_note_en: meta?.enforcement_note_en ?? '',
        today: meta?.today ?? '',
        project_currency: meta?.project_currency ?? 'SAR',
        budget: meta?.budget ?? null,
      }
    },
    enabled: Boolean(projectId),
  })
}

export interface NewSpendLimit {
  scope: SpendLimitScope
  scope_id?: string | null
  amount: number
  currency: string
  starts_on: string
  ends_on: string
  thresholds?: number[]
}

export function useCreateSpendLimit(projectId: string | null) {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (body: NewSpendLimit) => postData<SpendLimitReading>(`/projects/${projectId}/spend-limits`, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['spend-limits', projectId] }),
  })
}

/** The part of a limit that may be changed in place: how much, for how long, and when to warn. */
export interface SpendLimitEdit { amount: number; starts_on: string; ends_on: string; thresholds: number[] }

export function useUpdateSpendLimit(projectId: string | null) {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: ({ id, ...body }: SpendLimitEdit & { id: string }) => patchData<SpendLimitReading>(`/projects/${projectId}/spend-limits/${id}`, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['spend-limits', projectId] }),
  })
}

/**
 * Removing a limit DEACTIVATES it — the endpoint keeps the row.
 *
 * The events written against a limit are its audit trail, and a trail whose subject can vanish is
 * not one. What the operator sees is the limit leaving their list, which is what they asked for;
 * what the record keeps is last quarter's limit beside what was actually spent against it.
 */
export function useRemoveSpendLimit(projectId: string | null) {
  const qc = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => deleteData<null>(`/projects/${projectId}/spend-limits/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['spend-limits', projectId] }),
  })
}
