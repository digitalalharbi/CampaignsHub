import { deleteData, getData, postData } from '@/lib/api/client'

/**
 * Subscriptions API layer (Operations Console, internal). Mirrors the backend
 * App\Domains\Subscriptions\Http\Controllers\SubscriptionController:
 *  - GET  /subscriptions/plans    → the active plan catalogue (subscriptions.view).
 *  - GET  /subscriptions/current  → the tenant's subscription + honest per-metric usage/remaining.
 *  - POST /subscriptions/plan-change/quote → what a mid-term change would cost, changing nothing.
 *  - POST /subscriptions/plan-change       → commit to it (an upgrade opens a charge; a downgrade books a date).
 *  - DELETE /subscriptions/plan-change     → withdraw a change that has not taken effect.
 *
 * `POST /subscriptions/change` is deliberately NOT here. It is the platform owner's grant — a plan
 * assigned with no money — and it is now refused for everybody else. A customer changing their own
 * plan pays the prorated difference, which is what the endpoints above are for.
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

export interface ScheduledPlanChange {
  plan: string | null
  plan_name: string | null
  billing_interval: string | null
  unit_amount: string | null
  /** Null when the change is waiting on a PAYMENT rather than on the calendar. */
  effective_at: string | null
  awaiting_payment: boolean
}

export interface SubscriptionSummary {
  status: string
  seats: number
  billing_interval?: string | null
  unit_amount?: string | null
  currency?: string | null
  current_period_start?: string | null
  current_period_end: string | null
  /**
   * A change that is agreed but not in force.
   *
   * Reported apart from `plan` and never merged into it: until this takes effect the customer is
   * entitled to what `plan` says, and showing the coming plan as the current one would describe a
   * downgrade as already having happened.
   */
  scheduled_change?: ScheduledPlanChange | null
}

/**
 * How the NEXT payment will be taken — PAY-TOKEN-003.
 *
 * `reason` is one of four, and which one matters: `no_saved_method` is the customer's to fix, while
 * `no_gateway` and `provider_unsupported` belong to whoever runs the install. `card` is a label
 * («visa ···· 4242») and never a token — the credential is encrypted server-side and does not leave it.
 */
export interface RenewalMode {
  unattended: boolean
  reason: 'ready' | 'no_saved_method' | 'no_gateway' | 'provider_unsupported' | string
  card: string | null
}

export interface CurrentSubscription {
  subscription: SubscriptionSummary | null
  plan: SubscriptionPlan | null
  /** true when the tenant has no explicit subscription and defaulted to the most permissive plan. */
  is_default_plan: boolean
  usage: Record<string, UsageMetric>
  /** Null when there is no subscription to renew. */
  renewal?: RenewalMode | null
}

export const getCurrent = () => getData<CurrentSubscription>('/subscriptions/current')

/**
 * Take the card off file. Cancels nothing — the subscription and any commitment both stand, and the
 * next renewal simply arrives as an invoice to pay. There is deliberately no matching «add card»:
 * a card arrives one way only, from a payment the gateway settled.
 */
export const detachPaymentMethod = () =>
  deleteData<{ renewal: RenewalMode }>('/subscriptions/payment-method')

export type BillingInterval = 'monthly' | 'annual'

/** The arithmetic behind a mid-term change. Every amount is a decimal STRING — money is not a float. */
export interface ProrationQuote {
  direction: 'upgrade' | 'downgrade' | 'lateral'
  remaining_days: number
  period_days: number
  unused_fraction: number
  /** The unused part of what they already paid, credited against the new plan. */
  credit: string
  new_period_price: string
  prorated_new: string
  /** What is owed right now. `0.00` on a downgrade — nothing is taken and nothing is refunded. */
  due_now: string
  currency: string
  effective: 'immediate' | 'period_end'
  effective_at: string | null
}

export interface PlanChangeQuote {
  from: { plan: string | null; plan_name: string | null; interval: string | null; unit_amount: string | null }
  to: { plan: string; plan_name: string; interval: string }
  quote: ProrationQuote
}

export interface PlanChangeResult {
  quote: ProrationQuote
  effective: 'immediate' | 'period_end'
  effective_at: string | null
  /**
   * Present only when money is owed. `checkout_url` is null with no gateway configured, and the
   * status then says `awaiting_credentials` rather than pretending a payment page exists.
   */
  payment: { status: string; checkout_url: string | null; amount: string; currency: string } | null
  /** What the customer is entitled to RIGHT NOW — unchanged until a payment is confirmed. */
  plan: string | null
  scheduled_plan: string | null
}

/** What a change would cost. Opens no charge, writes no row, moves no plan — safe to call freely. */
export const quotePlanChange = (planCode: string, interval: BillingInterval) =>
  postData<PlanChangeQuote>('/subscriptions/plan-change/quote', { plan_code: planCode, billing_interval: interval })

/** Commit. An upgrade answers with a charge to pay; a downgrade answers with the date it starts. */
export const requestPlanChange = (planCode: string, interval: BillingInterval) =>
  postData<PlanChangeResult>('/subscriptions/plan-change', { plan_code: planCode, billing_interval: interval })

export const cancelPlanChange = () =>
  deleteData<{ scheduled_plan: null }>('/subscriptions/plan-change')

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
