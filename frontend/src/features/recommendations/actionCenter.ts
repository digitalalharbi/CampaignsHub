import { alertCategory, type AlertCategory } from '@/features/alerts/alertTaxonomy'
import type { AlertEvent } from '@/features/alerts/api'
import type { Recommendation, RecommendationPriority } from './api'
import type { SpendLimitReading } from '@/features/budget/spendLimitsApi'
import type { CreativeCard } from '@/features/content/api'

/**
 * RECOMMENDATIONS-ACTION-CENTER-002 — everything a person should act on, in one place.
 *
 * ## What the page was
 *
 * A list of recommendations somebody had WRITTEN. Truthful, and it meant an account where nobody had
 * written one showed nothing to do — while the product knew, at that same moment, that a budget was
 * breached, a creative had fatigued and a token was about to expire. Three engines with three
 * surfaces, and the page named «what should I do» showed none of them.
 *
 * ## What this does not do
 *
 * It derives no advice. Every item here is something another part of the product already decided and
 * already states: an alert `AlertEvaluator` raised, a limit `SpendLimitGovernor` read, a creative
 * `CreativeFatigue` judged, a recommendation a person wrote. This joins and ORDERS them, and each
 * item keeps its own source object so the row can render that source's own evidence — the alert's
 * context chips know their currency, and a generic reader here would not.
 *
 * ## Why the source travels with the item
 *
 * A derived signal presented as advice would be the product putting words in an operator's mouth.
 * «Fatigue says this creative is spent» and «Sara wrote: stop running this» are different claims
 * with different standing, and the reader is entitled to know which they are looking at.
 */
export type ActionSeverity = 'critical' | 'warning' | 'info'

export type ActionCategory = AlertCategory | 'creative'

export type ActionItem =
  | { id: string; kind: 'alert'; severity: ActionSeverity; category: ActionCategory; alert: AlertEvent }
  | { id: string; kind: 'budget'; severity: ActionSeverity; category: 'budget'; limit: SpendLimitReading }
  | { id: string; kind: 'creative'; severity: ActionSeverity; category: 'creative'; creative: CreativeCard }
  | { id: string; kind: 'written'; severity: ActionSeverity; category: ActionCategory; recommendation: Recommendation }

const SEVERITY_RANK: Record<ActionSeverity, number> = { critical: 0, warning: 1, info: 2 }

/**
 * A written recommendation's priority, read as a severity.
 *
 * `critical` and `high` are both things somebody said to do NOW, and the page already treats them
 * that way; `low` and an unset priority are the same statement — `priority` is NOT NULL DEFAULT
 * 'medium' on the table, so «nobody ranked this» and «somebody ranked it medium» are indistinguishable
 * and neither is urgent.
 */
function fromPriority(priority: RecommendationPriority | null): ActionSeverity {
  if (priority === 'critical') return 'critical'
  if (priority === 'high') return 'warning'

  return 'info'
}

/**
 * A spend limit's own state, read as a severity.
 *
 * `unknown` is INFO and not silence: a limit whose spend cannot be compared is not evidence that
 * anything is wrong, and it is not evidence that nothing is. Dropping it would report safety the
 * product cannot see; raising it to warning would put an unmeasurable thing above a measured breach.
 */
function fromLimitState(state: SpendLimitReading['state']): ActionSeverity | null {
  if (state === 'over') return 'critical'
  if (state === 'approaching') return 'warning'
  if (state === 'unknown') return 'info'

  return null
}

export function buildActionCentre(input: {
  alerts?: AlertEvent[]
  limits?: SpendLimitReading[]
  fatigued?: CreativeCard[]
  written?: Recommendation[]
}): ActionItem[] {
  const items: ActionItem[] = []

  /* Only what is still OPEN. A resolved alert is history, and history is not a thing to do. */
  for (const alert of input.alerts ?? []) {
    if (alert.status !== 'open') continue

    items.push({
      id: `alert:${alert.id}`,
      kind: 'alert',
      severity: alert.severity === 'critical' ? 'critical' : alert.severity === 'warning' ? 'warning' : 'info',
      category: alertCategory(alert.type),
      alert,
    })
  }

  for (const limit of input.limits ?? []) {
    const severity = fromLimitState(limit.state)

    /* A limit comfortably inside its budget is not an action — it is the normal case. */
    if (severity === null) continue

    items.push({ id: `budget:${limit.id}`, kind: 'budget', severity, category: 'budget', limit })
  }

  for (const creative of input.fatigued ?? []) {
    items.push({ id: `creative:${creative.id}`, kind: 'creative', severity: 'warning', category: 'creative', creative })
  }

  for (const recommendation of input.written ?? []) {
    /*
     * Hidden and rejected are decisions somebody already made. Showing them here would put a
     * rejected recommendation back in front of the person who rejected it.
     */
    if (recommendation.status === 'hidden' || recommendation.status === 'rejected') continue

    items.push({
      id: `written:${recommendation.id}`,
      kind: 'written',
      severity: fromPriority(recommendation.priority),
      category: 'other',
      recommendation,
    })
  }

  /*
   * Most serious first, and within a severity the order the sources were read in — which is stable.
   *
   * No cross-source ranking beyond severity: «is a breached budget more urgent than a critical alert»
   * has no answer this product can defend, and inventing one would be a score. Severity is a
   * judgement each engine already made about its own subject.
   */
  return items.sort((a, b) => SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity])
}

/** How many items each severity holds — for the strip that says what the queue is made of. */
export function actionCounts(items: ActionItem[]): Record<ActionSeverity, number> {
  return {
    critical: items.filter((i) => i.severity === 'critical').length,
    warning: items.filter((i) => i.severity === 'warning').length,
    info: items.filter((i) => i.severity === 'info').length,
  }
}
