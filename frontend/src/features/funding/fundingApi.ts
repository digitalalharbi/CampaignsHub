import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ensureCsrfCookie, getData, getEnvelope, postData } from '@/lib/api/client'

/**
 * FIN-LOOP-001 — a project's approved budget, read from the endpoints that compute it.
 *
 * Nothing here derives a figure: approved, spent, remaining, pace, days left and the status are all
 * computed by `FundingBalance` on the server, so this page cannot disagree with the notices or the export.
 */
export type BudgetStatus = 'ok' | 'low' | 'over' | 'incomplete' | 'no_budget' | 'direct' | 'unset'

export interface BudgetRow {
  project_id: string
  client_workspace_id: string
  project_name: string | null
  client_name: string | null
  payment_method: 'prepaid' | 'direct' | 'unset'
  counts_from: string | null
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
  by_platform: { provider: string; spent: number }[]
  status: BudgetStatus
}

export interface BudgetPayment {
  id: string
  kind: 'initial' | 'top_up'
  amount: number
  currency: string
  amount_sar: number
  paid_on: string
  reference: string | null
  note: string | null
  remaining_before: number | null
  remaining_after: number | null
  added_by: string | null
  added_at: string
}

export interface BudgetPoint { date: string; approved: number; spent: number; remaining: number | null; added: number }

export interface BudgetDetail extends BudgetRow {
  history: BudgetPayment[]
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

export function useBudgets() {
  return useQuery({
    queryKey: ['funding'],
    queryFn: async () => {
      const env = await getEnvelope<BudgetRow[]>('/funding')
      const meta = (env.meta ?? {}) as { totals?: BudgetTotals; can_manage?: boolean }
      return { rows: env.data, totals: meta.totals ?? null, canManage: meta.can_manage === true }
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

/**
 * Downloads the budgets file the server builds; the server applies the reader's reach. Resolves false
 * when the file could not be fetched, so the caller can say so instead of failing silently.
 */
export async function downloadBudgets(
  format: 'xlsx' | 'csv',
  filters: { client_id?: string; project_id?: string; from?: string; to?: string } = {},
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
