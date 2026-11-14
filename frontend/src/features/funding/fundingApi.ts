import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ensureCsrfCookie, getData, getEnvelope, postData } from '@/lib/api/client'

/**
 * FIN-LOOP-001 — a project's approved budget, read from the endpoints that compute it.
 *
 * Nothing here derives a figure: approved, spent, remaining, pace, days left and the status are all
 * computed by `FundingBalance` on the server, so this page cannot disagree with the notices or the export.
 */
export type BudgetStatus = 'ok' | 'low' | 'over' | 'incomplete' | 'no_budget' | 'direct' | 'unset' | 'void'

export interface BudgetRow {
  project_id: string
  client_workspace_id: string
  project_name: string | null
  client_name: string | null
  payment_method: 'prepaid' | 'direct' | 'unset'
  counts_from: string | null
  /** The period: first day of spend counted (stated, else the first day of real spend) and the last (null = open). */
  period_start: string | null
  period_end: string | null
  period_start_stated: boolean
  voided_at: string | null
  void_reason: string | null
  alert_floor: number
  currency: 'SAR'
  approved: number
  payments: number
  spent: number
  remaining: number | null
  used_ratio: number | null
  pace_per_day: number | null
  days_left: number | null
  spend_without_rate: number
  complete: boolean
  last_spend_day: string | null
  by_platform: { provider: string; spent: number; unconverted_days?: number }[]
  status: BudgetStatus
}

export interface BudgetPayment {
  id: string
  kind: 'initial' | 'top_up' | 'adjustment' | 'void' | 'reversal'
  amount: number
  currency: string
  amount_sar: number
  paid_on: string
  reference: string | null
  note: string | null
  reason: string | null
  reverses_id: string | null
  /** Cancelled by a later reversal. */
  reversed: boolean
  /** True on the latest entry only: the one «تراجع» can undo. */
  can_undo: boolean
  remaining_before: number | null
  remaining_after: number | null
  added_by: string | null
  added_at: string
}

export interface BudgetPoint { date: string; approved: number; spent: number; remaining: number | null; added: number }

export interface BudgetDetail extends BudgetRow {
  history: BudgetPayment[]
  undo_entry_id: string | null
  series: BudgetPoint[]
  months: string[]
}

export interface BudgetMonth {
  month: string
  from: string
  to: string
  remaining_start: number | null
  added: number
  spent: number
  remaining_end: number | null
  approved_total: number
  by_platform: { provider: string; spent: number }[]
  movements: { date: string; kind: string; amount: number; currency: string; amount_sar: number; reference: string | null; added_by: string | null }[]
  spend_without_rate: number
  fixed: boolean
  fixed_at: string | null
}

export interface BudgetTotals { approved: number; spent: number; remaining: number; needs_attention: number; currency: 'SAR' }

export type BudgetLedgerResult = { entry_id: string } & BudgetRow

/** `deleted` reads the deleted budgets instead (the «المحذوفة» view): hidden by default, never removed. */
export function useBudgets(deleted = false) {
  return useQuery({
    queryKey: ['funding', { deleted }],
    queryFn: async () => {
      const env = await getEnvelope<BudgetRow[]>(deleted ? '/funding?deleted=1' : '/funding')
      const meta = (env.meta ?? {}) as { totals?: BudgetTotals; can_manage?: boolean; deleted_count?: number }
      return { rows: env.data, totals: meta.totals ?? null, canManage: meta.can_manage === true, deletedCount: meta.deleted_count ?? 0 }
    },
  })
}

export function useBudget(projectId: string | null) {
  return useQuery({
    queryKey: ['funding', projectId],
    enabled: projectId !== null,
    queryFn: () => getData<BudgetDetail>(`/funding/projects/${projectId}`),
  })
}

export function useBudgetMonth(projectId: string | null, month: string | null) {
  return useQuery({
    queryKey: ['funding', projectId, 'month', month],
    enabled: projectId !== null && month !== null,
    queryFn: () => getData<BudgetMonth>(`/funding/projects/${projectId}/months/${month}`),
  })
}

export interface NewBudget {
  client_workspace_id?: string
  client_name?: string
  project_id?: string
  project_name?: string
  amount: number
  paid_on: string
  period_start?: string | null
  period_end?: string | null
  reference?: string
  idempotency_key: string
}

export function useAddBudget() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (body: NewBudget) => {
      await ensureCsrfCookie()
      return postData<BudgetRow & { payment_id: string; created: boolean }>('/funding/payments', body)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['funding'] }),
  })
}

export interface BudgetEdit {
  amount?: number
  period_start?: string | null
  period_end?: string | null
  note?: string | null
}

/** Edit, delete and undo are entries on the ledger, never changes of it; every answer carries the new figures. */
export function useAdjustBudget() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ projectId, body }: { projectId: string; body: BudgetEdit }) => {
      await ensureCsrfCookie()
      return postData<BudgetLedgerResult>(`/funding/projects/${projectId}/adjust`, body)
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['funding'] }),
  })
}

export function useVoidBudget() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ projectId, reason }: { projectId: string; reason: string }) => {
      await ensureCsrfCookie()
      return postData<BudgetLedgerResult>(`/funding/projects/${projectId}/void`, { reason })
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['funding'] }),
  })
}

export function useUndoBudget() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ projectId, entryId }: { projectId: string; entryId?: string | null }) => {
      await ensureCsrfCookie()
      return postData<BudgetLedgerResult>(`/funding/projects/${projectId}/undo`, entryId ? { entry_id: entryId } : {})
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['funding'] }),
  })
}

/**
 * Downloads the budgets file the server builds; the server applies the reader's reach. Resolves false
 * when the file could not be fetched, so the caller can say so instead of failing silently.
 */
export async function downloadBudgets(
  format: 'xlsx' | 'csv' | 'pdf',
  filters: { client_id?: string; project_id?: string; from?: string; to?: string; history?: string; lang?: string } = {},
): Promise<boolean> {
  const params = new URLSearchParams({ format })
  for (const [k, v] of Object.entries(filters)) if (v) params.set(k, v)
  try {
    const res = await api.get(`/funding/export?${params.toString()}`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `budgets-${filters.from ? `${filters.from.slice(0, 7)}-` : ''}${new Date().toISOString().slice(0, 10)}.${format}`
    a.click()
    // Revoked a moment later: some browsers cancel a download whose address is revoked at once.
    setTimeout(() => URL.revokeObjectURL(url), 30_000)

    return true
  } catch {
    return false
  }
}
