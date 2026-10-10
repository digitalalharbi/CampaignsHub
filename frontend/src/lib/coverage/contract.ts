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
  /** Reported, but not through the end of the window (CAMPAIGN-KPI-COVERAGE-001). */
  partial_contributors?: string[]
  /** Contributor → the last date its figures cover, for the partial and stale ones. */
  reported_through?: Record<string, string>
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
export function readCoverage(totals: object | undefined, key?: string): Coverage {
  // Typed payloads (MetricTotals and friends) have no index signature; the block is read by name.
  const t = totals as Record<string, unknown> | undefined
  const named = key ? (t?.[`${key}_coverage`] as Coverage | undefined) : undefined
  const generic = t?.['coverage'] as Coverage | undefined

  return named ?? generic ?? { state: 'complete' }
}

/** Whether the backend actually stated coverage, as opposed to this defaulting to complete. */
export function isStated(totals: object | undefined, key?: string): boolean {
  const t = totals as Record<string, unknown> | undefined
  return Boolean((key ? t?.[`${key}_coverage`] : undefined) ?? t?.['coverage'])
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
  return isComplete(coverage) || truncatedOnly(coverage)
}

/**
 * Whether the ONLY thing wrong with this coverage is that every missing contributor stopped short
 * of the window's end — reported, but not through the last day.
 *
 * That is a different defect from a missing contributor. A total over Meta-through-the-27th is the
 * true total for the days through the 27th, and the CPA over those days is the true CPA for those
 * days; what is wrong is the window label, not the arithmetic. A total missing Snapchat altogether is
 * a different quantity, and a ratio over it is a different ratio. The first may be read with the
 * covered date stated; the second may not be derived from at all.
 */
export function truncatedOnly(coverage: Coverage): boolean {
  const excluded = coverage.excluded_contributors ?? []
  const partial = new Set(coverage.partial_contributors ?? [])
  return excluded.length > 0 && excluded.every((c) => partial.has(c))
}

/**
 * The last date every partial contributor reported through — one date when they agree, null when
 * they do not or when nothing is partial. The date the KPI block can say «through» about.
 */
export function reportedThrough(coverage: Coverage): string | null {
  const dates = new Set((coverage.partial_contributors ?? []).map((c) => coverage.reported_through?.[c]).filter((d): d is string => Boolean(d)))
  return dates.size === 1 ? [...dates][0] : null
}

/**
 * A short, honest sentence naming who is missing and why — or null when nothing is.
 *
 * Names the contributors rather than saying «some data is missing», because a reader who knows it is
 * Meta, and that its sync failed, can decide whether to re-authorise, wait, or read the number anyway.
 * A reader told only that something is missing can do none of those.
 */
export function coverageNote(coverage: Coverage, ar: boolean, label: (contributor: string) => string = (c) => c): string | null {
  if (isComplete(coverage)) return null

  const parts: string[] = []
  const add = (list: string[] | undefined, arWord: string, enWord: string) => {
    if (list && list.length > 0) parts.push(`${list.map(label).join('، ')} ${ar ? arWord : enWord}`)
  }

  add(coverage.failed_contributors, 'تعذّرت مزامنتها', 'failed to sync')
  add(coverage.stale_contributors, 'لم تُزامن حتى نهاية الفترة', 'is not synced through the end of this period')
  add(coverage.withheld_contributors, 'بلا سعر صرف', 'has no exchange rate')
  /*
   * Reported, but not through the end: name the date, because «through the 27th» is something a
   * reader can act on (wait, or read it as the 27th's figure) and «incomplete» is not.
   */
  for (const c of coverage.partial_contributors ?? []) {
    const through = coverage.reported_through?.[c]
    parts.push(through
      ? (ar ? `${label(c)} أبلغت حتى ${through} فقط` : `${label(c)} reported through ${through} only`)
      : (ar ? `${label(c)} لم تُبلّغ حتى نهاية الفترة` : `${label(c)} did not report through the end of this period`))
  }

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

/**
 * ANALYTICS-COVERAGE-COMPARABILITY-001 — whether a previous-period comparison may be stated at all.
 *
 * Two windows compare only when both are whole: the previous one holds rows (`previous_rows_in_scope`,
 * CAMP-COMPARE-001) AND neither window is partial. A thirty-day window whose platform reported
 * through its seventeenth day, set against a complete thirty-day window before it, yields a delta
 * that compares seventeen days with thirty — printed as «+18 %», it reads as growth.
 */
export function comparableWindows(summary: { previous_rows_in_scope?: boolean; current?: object; previous?: object } | undefined): boolean {
  if (!summary) return true
  if (summary.previous_rows_in_scope === false) return false
  return isComplete(readCoverage(summary.current)) && isComplete(readCoverage(summary.previous))
}

/** Why the comparison is withheld, when it is — or null when the windows compare. */
export function windowsUnlikeNote(
  summary: { previous_rows_in_scope?: boolean; previous_range?: { from: string; to: string }; current?: object; previous?: object } | undefined,
  ar: boolean,
  label: (contributor: string) => string = (c) => c,
): string | null {
  if (!summary || comparableWindows(summary)) return null
  if (summary.previous_rows_in_scope === false) {
    const r = summary.previous_range
    const span = r ? `(${r.from} → ${r.to}) ` : ''
    return ar
      ? `الفترة السابقة ${span}لا تحتوي أي بيانات، فلا يوجد شيء تُقاس عليه هذه الفترة.`
      : `The previous period ${span}holds no data, so there is nothing for this one to be measured against.`
  }
  const current = readCoverage(summary.current)
  const previous = readCoverage(summary.previous)
  const which = !isComplete(current) ? current : previous
  const through = reportedThrough(which)
  const partial = which.partial_contributors ?? []
  const who = (partial.length > 0 ? partial : which.excluded_contributors ?? []).map(label).join(ar ? '، ' : ', ')
  const side = !isComplete(current) ? (ar ? 'هذه الفترة' : 'This period') : (ar ? 'الفترة السابقة' : 'The previous period')
  if (through) {
    return ar
      ? `${side} مغطاة حتى ${through} فقط (${who} لم تُبلّغ بعدها)، فالفترتان غير متكافئتين ولا تُقاس إحداهما على الأخرى.`
      : `${side} is covered through ${through} only (${who} did not report after it), so the two windows are not alike and neither is measured against the other.`
  }
  return ar
    ? `${side} غير مكتملة (${who})، فالفترتان غير متكافئتين ولا تُقاس إحداهما على الأخرى.`
    : `${side} is incomplete (${who}), so the two windows are not alike and neither is measured against the other.`
}
