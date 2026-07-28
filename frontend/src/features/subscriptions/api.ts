import { getData, postData } from '@/lib/api/client'

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
