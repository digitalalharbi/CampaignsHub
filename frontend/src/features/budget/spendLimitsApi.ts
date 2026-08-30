import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { getEnvelope, postData } from '@/lib/api/client'

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
}

export interface SpendLimitsPage {
  limits: SpendLimitReading[]
  enforcement_note_ar: string
  enforcement_note_en: string
  today: string
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
