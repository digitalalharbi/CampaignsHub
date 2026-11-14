import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ensureCsrfCookie, getData, getEnvelope, postData } from '@/lib/api/client'

/**
 * FIN-LOOP-001 — a project's approved budget, read from the endpoints that compute it.
 *
 * Nothing here derives a figure: approved, spent, remaining, pace, days left and the status are all
 * computed by `FundingBalance` on the server, so this page cannot disagree with the notices or the export.
 */
export type BudgetStatus = 'ok' | 'low' | 'over' | 'incomplete' | 'no_budget' | 'direct' | 'unset' | 'void' | 'credit' | 'credit_near' | 'credit_over'

/** BUDGET-MODES-001 — direct: a prepaid balance. credit: spend recorded, invoiced later by the month; the amount is only a ceiling. */
export type PaymentMode = 'direct' | 'credit'

/** A platform's planned share. `amount` is derived from the budget on the server; null while the budget has no amount. */
export interface PlanShare { provider: string; percent: number; amount: number | null }

/** One platform's plan against what it spent — measured facts only, never a cause. */
export interface PlanRow {
  provider: string
  percent: number
  planned: number | null
  planned_usd: number | null
  spent: number
  spent_usd: number | null
  share_used: number | null
  pace_per_day: number | null
  projected: number | null
  expected_to_date: number | null
  variance: number | null
  flag: 'over' | 'near' | 'ahead' | 'behind' | 'unplanned' | null
  unconverted_days: number
}

export interface InvoiceMonth {
  month: string
  from: string
  to: string
  closed: boolean
  spent: number
  unconverted_days: number
  last_spend_day: string | null
  by_platform: { provider: string; spent: number }[]
}

export interface BudgetRow {
  project_id: string
  client_workspace_id: string
  project_name: string | null
  client_name: string | null
  payment_method: 'prepaid' | 'direct' | 'unset'
  payment_mode: PaymentMode
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
  /** `spent` in SAR (it adds up to the totals); `spent_usd` is the platform's own figure in USD, null with no rate; `unconverted` keeps days with no rate in their own currency. */
  by_platform: { provider: string; spent: number; spent_usd?: number | null; unconverted_days?: number; unconverted?: { currency: string; amount: number }[] }[]
  this_month: number | null
  usd_rate: number | null
  status: BudgetStatus
  /** credit: spend so far, the optional ceiling with its own ratio, and the closed months' spend (ready to invoice). */
  to_date: number | null
  ceiling: number | null
  ceiling_ratio: number | null
  to_invoice: number | null
  planned: boolean
  platform_plan: PlanShare[] | null
  plan: PlanRow[] | null
  plan_alerts: number
}

export interface BudgetPayment {
  id: string
  kind: 'initial' | 'top_up' | 'adjustment' | 'void' | 'reversal' | 'reallocation'
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
  plan_before?: { provider: string; percent: number }[] | null
  plan_after?: { provider: string; percent: number }[] | null
}

export interface BudgetPoint { date: string; approved: number; spent: number; remaining: number | null; added: number }

export interface BudgetDetail extends BudgetRow {
  history: BudgetPayment[]
  undo_entry_id: string | null
  series: BudgetPoint[]
  months: string[]
  invoice_months: InvoiceMonth[]
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

export interface BudgetTotals {
  approved: number
  spent: number
  remaining: number
  needs_attention: number
  credit_count?: number
  credit_spent?: number
  credit_to_invoice?: number
  currency: 'SAR'
}

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
  /** Optional on a credit budget (no ceiling); required on a direct one. */
  amount?: number
  payment_mode?: PaymentMode
  platform_plan?: PlanInput[]
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

/** A plan row as typed: an amount OR a percentage for every row, never mixed. */
export interface PlanInput { provider: string; amount?: number; percent?: number }

export interface BudgetEdit {
  amount?: number
  payment_mode?: PaymentMode
  /** null removes the plan. */
  platform_plan?: PlanInput[] | null
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

/** «أعد التوزيع»: with no plan it is the one-click rebalance on what was spent; with a plan it is that plan. Undoable like any entry. */
export function useReallocateBudget() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async ({ projectId, reason, platform_plan }: { projectId: string; reason?: string; platform_plan?: PlanInput[] }) => {
      await ensureCsrfCookie()
      return postData<BudgetLedgerResult>(`/funding/projects/${projectId}/reallocate`, { reason, platform_plan })
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['funding'] }),
  })
}

/** BUDGET-DISCLAIMER-001 — the one financial disclaimer, read from the server so no surface carries its own wording. */
export function useFinancialDisclaimer() {
  return useQuery({
    queryKey: ['funding', 'disclaimer'],
    staleTime: Infinity,
    retry: 1,
    queryFn: () => getData<{ ar: string; en: string }>('/funding/disclaimer'),
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
  filters: { client_id?: string; project_id?: string; from?: string; to?: string; history?: string; lang?: string; sheet?: string } = {},
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
