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
  // A limit of 0 with nothing used is «not on this plan», not «you reached it».
  if (metric.limit <= 0) return metric.used > 0 ? 'full' : 'ok'

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

/**
 * The one limit the header chip speaks about: the most used of the capped ones (largest used/limit), because the question
 * it answers is «how close am I to being stopped from adding?». An unmetered or zero-limit metric never leads; with
 * nothing capped there is no chip. Ties keep the order the server sent, so the pick is stable between reloads.
 */
export function pickMeter(usage: Record<string, UsageMetric>): { metric: string; used: number; limit: number; state: Threshold } | null {
  let best: { metric: string; used: number; limit: number; ratio: number } | null = null

  for (const [metric, u] of Object.entries(usage)) {
    if (u.limit === null || u.limit <= 0) continue
    const ratio = u.used / u.limit
    if (best === null || ratio > best.ratio) best = { metric, used: u.used, limit: u.limit, ratio }
  }

  return best === null ? null : { metric: best.metric, used: best.used, limit: best.limit, state: thresholdOf({ limit: best.limit, used: best.used, remaining: Math.max(0, best.limit - best.used) }) }
}

/** Short names for the counted limits, for places too narrow for a table (the header chip, the dialog). */
export const METER_LABELS: Record<string, { ar: string; en: string }> = {
  projects: { ar: 'المشاريع', en: 'Projects' },
  clients: { ar: 'العملاء', en: 'Clients' },
  team_members: { ar: 'الفريق', en: 'Team' },
  ad_accounts: { ar: 'الحسابات', en: 'Accounts' },
  connections: { ar: 'الربط', en: 'Connections' },
  reports_per_month: { ar: 'التقارير', en: 'Reports' },
}
