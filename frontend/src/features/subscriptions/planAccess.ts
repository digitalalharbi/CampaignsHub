import type { SubscriptionPlan, UsageMetric } from './api'

/**
 * NAV-HOME-PLANS-001 (master task 14) — what a plan decides, read from the plans the admin console defines.
 *
 * No number and no feature name is written here: limits arrive in `plan.limits` and features in `plan.features`, both
 * edited by the platform owner. This file only COMPARES them, so changing a plan in the console changes this page with
 * no release.
 */

export type Threshold = 'unlimited' | 'ok' | 'near' | 'high' | 'full'

/** The Owner's three alert points: 75%, 90% and 100%. At 100% only NEW things are refused — nothing running stops. */
export function thresholdOf(metric: UsageMetric): Threshold {
  if (metric.limit === null) return 'unlimited'
  if (metric.limit <= 0) return 'full'

  const ratio = metric.used / metric.limit
  if (ratio >= 1) return 'full'
  if (ratio >= 0.9) return 'high'

  return ratio >= 0.75 ? 'near' : 'ok'
}

const WEIGHT: Record<Threshold, number> = { unlimited: 0, ok: 1, near: 2, high: 3, full: 4 }

/** The worst point reached by any limit — drives the one line of advice above the rings. */
export function worstThreshold(usage: Record<string, UsageMetric>): Threshold {
  return Object.values(usage).map(thresholdOf).reduce<Threshold>((worst, t) => (WEIGHT[t] > WEIGHT[worst] ? t : worst), 'unlimited')
}

/** A feature counts as included when its value is true, a non-empty string, or a positive number. */
export function includes(plan: SubscriptionPlan, feature: string): boolean {
  const v = plan.features[feature]

  return v === true || (typeof v === 'string' && v !== '' && v !== 'none') || (typeof v === 'number' && v > 0)
}

/** Cheapest first — the order a reader expects a ladder in, and the one «which plan has it» is answered against. */
export function ladder(plans: readonly SubscriptionPlan[]): SubscriptionPlan[] {
  return [...plans].sort((a, b) => Number(a.price_monthly) - Number(b.price_monthly))
}

/** The cheapest plan that includes the feature — the name a locked feature shows. Null when no plan does. */
export function planWith(plans: readonly SubscriptionPlan[], feature: string): SubscriptionPlan | null {
  return ladder(plans).find((p) => includes(p, feature)) ?? null
}

export function featureKeys(plans: readonly SubscriptionPlan[]): string[] {
  return [...new Set(plans.flatMap((p) => Object.keys(p.features)))]
}

export function limitKeys(plans: readonly SubscriptionPlan[]): string[] {
  return [...new Set(plans.flatMap((p) => Object.keys(p.limits)))]
}
