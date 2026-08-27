/**
 * AGGREGATION-TRUTH-001 — the frontend reads coverage; it does not deduce it.
 *
 * ## The rule this file exists to hold
 *
 * A number alone cannot say whether it is the whole answer. `0` is produced equally by a platform
 * that spent nothing and by a platform whose sync failed; `null` is produced equally by a metric
 * nobody supports and by money nobody could convert. Every attempt to recover the difference in the
 * client — «if it's 0 and the platform is connected, then…» — is the backend's evidence being guessed
 * at from its shadow, and it goes wrong silently.
 *
 * So the backend states it, and this reads what was stated. There is deliberately no inference here:
 * no thresholds, no «looks empty», no reconstructing intent from the shape of a value.
 */

/** The states a contributor can be in. Mirrors `ContributionState` on the backend, by name. */
export type ContributionState =
  | 'REPORTED_VALUE'
  | 'REPORTED_ZERO'
  | 'INACTIVE'
  | 'NO_ACTIVITY'
  | 'NOT_REPORTED'
  | 'UNSUPPORTED'
  | 'WITHHELD_FX'
  | 'PARTIAL'
  | 'STALE'
  | 'FAILED'
  | 'UNKNOWN'

/** What `AggregateCoverage::toArray()` emits beside every total. */
export type Coverage = {
  state: 'complete' | 'partial'
  expected_contributors?: string[]
  included_contributors?: string[]
  inactive_contributors?: string[]
  stale_contributors?: string[]
  failed_contributors?: string[]
  withheld_contributors?: string[]
  unsupported_contributors?: string[]
  excluded_contributors?: string[]
  reasons?: Record<string, string>
}

/**
 * Read the coverage that belongs to one figure.
 *
 * An ABSENT coverage block is treated as complete, and that is a deliberate compatibility choice
 * rather than an oversight: every payload predating this contract has no coverage, and defaulting the
 * other way would mark the entire product partial on the day it shipped — which is itself a false
 * statement, and a louder one. A surface that must distinguish «proven complete» from «never said»
 * should ask `isStated()`.
 */
export function readCoverage(totals: Record<string, unknown> | undefined, key?: string): Coverage {
  const named = key ? (totals?.[`${key}_coverage`] as Coverage | undefined) : undefined
  const generic = totals?.['coverage'] as Coverage | undefined

  return named ?? generic ?? { state: 'complete' }
}

/** Whether the backend actually stated coverage, as opposed to this defaulting to complete. */
export function isStated(totals: Record<string, unknown> | undefined, key?: string): boolean {
  return Boolean((key ? totals?.[`${key}_coverage`] : undefined) ?? totals?.['coverage'])
}

/** Whether this figure may be presented as the complete answer to its question. */
export function isComplete(coverage: Coverage): boolean {
  return coverage.state !== 'partial'
}

/**
 * Whether a DERIVED figure may be shown — a ratio, a cost-per, a rank.
 *
 * Deliberately stricter in spirit than `isComplete`, and identical in effect for now: a ratio inherits
 * the incompleteness of both its parts, and «CPA 21.00» computed over two thirds of the spend is not
 * an approximate CPA. It is a different quantity wearing the CPA's name, and no caption beside it
 * survives the screenshot.
 */
export function allowsDerived(coverage: Coverage): boolean {
  return isComplete(coverage)
}

/**
 * A short, honest sentence naming who is missing and why — or null when nothing is.
 *
 * Names the contributors rather than saying «some data is missing», because a reader who knows it is
 * Meta, and that its sync failed, can decide whether to re-authorise, wait, or read the number anyway.
 * A reader told only that something is missing can do none of those.
 */
export function coverageNote(coverage: Coverage, ar: boolean): string | null {
  if (isComplete(coverage)) return null

  const parts: string[] = []
  const add = (list: string[] | undefined, arWord: string, enWord: string) => {
    if (list && list.length > 0) parts.push(`${list.join('، ')} ${ar ? arWord : enWord}`)
  }

  add(coverage.failed_contributors, 'تعذّرت مزامنتها', 'failed to sync')
  add(coverage.stale_contributors, 'لم تُزامن حتى نهاية الفترة', 'is not synced through the end of this period')
  add(coverage.withheld_contributors, 'بلا سعر صرف', 'has no exchange rate')

  if (parts.length === 0) {
    // Partial for a reason this build does not have wording for. Say that, rather than inventing one.
    return ar
      ? 'هذا الرقم لا يشمل كل المصادر المتوقعة لهذه الفترة.'
      : 'This figure does not include every contributor expected for this period.'
  }

  return ar
    ? `هذا الرقم غير مكتمل: ${parts.join('؛ ')}.`
    : `This figure is incomplete: ${parts.join('; ')}.`
}
