import { deleteData, getData, postData } from '@/lib/api/client'

/**
 * Subscriptions API layer (Operations Console, internal). Mirrors the backend
 * App\Domains\Subscriptions\Http\Controllers\SubscriptionController:
 *  - GET  /subscriptions/plans    → the active plan catalogue (subscriptions.view).
 *  - GET  /subscriptions/current  → the tenant's subscription + honest per-metric usage/remaining.
 *  - POST /subscriptions/change   → move the tenant onto a plan by its `code` (subscriptions.manage).
 *
 * Honesty notes: a `limit`/`remaining` of `null` means UNLIMITED (the plan does not cap that metric). Plans
 * are identified by their string `code` (there is no numeric id in the client-safe shape) — so "changing plan"
 * is keyed by `plan_code`.
 */

export interface SubscriptionPlan {
  code: string
  name: string
  price_monthly: string
  currency: string
  /** Feature flags/values, e.g. `{ support: 'priority', ai_assist: true, white_label: true }`. */
  features: Record<string, string | number | boolean>
  /** Per-metric caps; `null` = unlimited. */
  limits: Record<string, number | null>
}

export const getPlans = () => getData<SubscriptionPlan[]>('/subscriptions/plans')

/** One metered metric: `limit`/`remaining` are `null` when the plan is unlimited for that metric. */
export interface UsageMetric {
  limit: number | null
  used: number
  remaining: number | null
}

export interface SubscriptionSummary {
  status: string
  seats: number
  current_period_end: string | null
}

export interface CurrentSubscription {
  subscription: SubscriptionSummary | null
  plan: SubscriptionPlan | null
  /** true when the tenant has no explicit subscription and defaulted to the most permissive plan. */
  is_default_plan: boolean
  usage: Record<string, UsageMetric>
}

export const getCurrent = () => getData<CurrentSubscription>('/subscriptions/current')

export interface ChangePlanResult {
  subscription: SubscriptionSummary
  plan: SubscriptionPlan
}

/** Move the tenant onto a plan, keyed by the plan's `code`. Requires subscriptions.manage server-side. */
export const changePlan = (planCode: string) =>
  postData<ChangePlanResult>('/subscriptions/change', { plan_code: planCode })

/*
 * CampaignsHub's own invoices to this customer (SUBINV-001).
 *
 * NOT the agency's invoices to its own clients — those live under `/billing` and answer to a
 * different permission. The two are kept apart because whose tax number appears on a document and
 * who may read it are different questions.
 */

export interface SubscriptionInvoiceLine {
  description: string
  plan_code: string | null
  period: string | null
  quantity: string
  unit_price: string
  discount: string
  line_total: string
}

export interface SubscriptionInvoice {
  id: string
  /** Human-readable and sequential — what a customer quotes back and an accountant reconciles. */
  number: string
  status: 'issued' | 'paid' | 'refunded' | 'void' | string
  bill_to: { name: string; email: string; tax_number: string | null }
  currency: string
  subtotal: string
  discount_total: string
  /** The treatment, not only the rate: `zero_rated` and `exempt` both compute to zero and differ. */
  tax_treatment: string
  tax_rate: string
  tax_total: string
  total: string
  amount_paid: string
  outstanding: string
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
  refunded_at: string | null
  void_reason: string | null
  is_shared: boolean
  share_url: string | null
  lines: SubscriptionInvoiceLine[]
}

export const listSubscriptionInvoices = () =>
  getData<{ invoices: SubscriptionInvoice[] }>('/subscriptions/invoices')

export const shareSubscriptionInvoice = (id: string) =>
  postData<{ share_url: string | null; invoice: SubscriptionInvoice }>(`/subscriptions/invoices/${id}/share`, {})

export const revokeSubscriptionInvoiceShare = (id: string) =>
  deleteData<{ invoice: SubscriptionInvoice }>(`/subscriptions/invoices/${id}/share`)

/** The download is a real endpoint, so the link is a real link rather than a rendered blob. */
export const subscriptionInvoiceDownloadUrl = (id: string) =>
  `/api/v1/subscriptions/invoices/${id}/download`
