import { describe, expect, it } from 'vitest'
import { allowsDerived, comparableWindows, coverageNote, isComplete, isStated, readCoverage, reportedThrough, truncatedOnly, windowsUnlikeNote, type Coverage } from './contract'

/**
 * AGGREGATION-TRUTH-001 on the client — the frontend reads truth and never reconstructs it.
 *
 * Each of these encodes a distinction the old code could not make, because it only had the number:
 * `0` from a platform that spent nothing looks exactly like `0` from a platform whose sync failed.
 */
describe('reading coverage', () => {
  const partial: Coverage = {
    state: 'partial',
    expected_contributors: ['snapchat', 'tiktok', 'meta'],
    included_contributors: ['snapchat', 'tiktok'],
    failed_contributors: ['meta'],
    excluded_contributors: ['meta'],
    reasons: { meta: 'The last sync failed: OAuthException' },
  }

  it('prefers the coverage belonging to the figure being read', () => {
    const totals = { coverage: { state: 'complete' }, spend_coverage: partial }

    expect(isComplete(readCoverage(totals, 'spend'))).toBe(false)
    expect(isComplete(readCoverage(totals))).toBe(true)
  })

  /**
   * An absent block reads as complete ON PURPOSE. Every payload predating this contract has none, and
   * defaulting the other way would mark the whole product partial on the day it shipped — a false
   * statement, and a louder one than the silence it replaced.
   */
  it('treats an absent coverage block as complete, but can say it was never stated', () => {
    expect(isComplete(readCoverage({}, 'spend'))).toBe(true)
    expect(isStated({}, 'spend')).toBe(false)
    expect(isStated({ spend_coverage: partial }, 'spend')).toBe(true)
  })

  /** A ratio inherits the incompleteness of both its parts. */
  it('refuses derived figures on partial coverage', () => {
    expect(allowsDerived(partial)).toBe(false)
    expect(allowsDerived({ state: 'complete' })).toBe(true)
  })

  it('says nothing when there is nothing to say', () => {
    expect(coverageNote({ state: 'complete' }, false)).toBeNull()
  })

  /**
   * Names the contributor and the reason. «Some data is missing» is unactionable; «meta failed to
   * sync» tells a reader whether to re-authorise, wait, or read the number anyway.
   */
  it('names who is missing and why, rather than saying data is missing', () => {
    const note = coverageNote(partial, false)

    expect(note).toContain('meta')
    expect(note).toContain('failed to sync')
  })

  /** Partial for a reason this build has no wording for still says «incomplete», never «complete». */
  it('admits incompleteness even when it cannot name the reason', () => {
    const note = coverageNote({ state: 'partial', excluded_contributors: ['x'] }, false)

    expect(note).not.toBeNull()
    expect(note).toContain('does not include every contributor')
  })
})

/*
 * CAMPAIGN-KPI-COVERAGE-001 — a contributor that reported, but not through the end of the window.
 */
describe('a window every contributor reported through the same earlier date', () => {
  const truncated: Coverage = {
    state: 'partial',
    expected_contributors: ['meta'],
    included_contributors: [],
    excluded_contributors: ['meta'],
    partial_contributors: ['meta'],
    reported_through: { meta: '2026-09-27' },
    reasons: { meta: 'Reported through 2026-09-27; this window ends 2026-10-10.' },
  }

  it('is incomplete, but its ratios are the ratios for the covered days', () => {
    expect(isComplete(truncated)).toBe(false)
    expect(truncatedOnly(truncated)).toBe(true)
    expect(allowsDerived(truncated)).toBe(true)
    expect(reportedThrough(truncated)).toBe('2026-09-27')
  })

  it('loses that allowance the moment a contributor is missing outright', () => {
    const mixed: Coverage = { ...truncated, excluded_contributors: ['meta', 'snapchat'], stale_contributors: ['snapchat'] }
    expect(truncatedOnly(mixed)).toBe(false)
    expect(allowsDerived(mixed)).toBe(false)
  })

  it('names the contributor and the date, in the reader\'s words for the contributor', () => {
    expect(coverageNote(truncated, true, (c) => (c === 'meta' ? 'ميتا' : c))).toBe('هذا الرقم غير مكتمل: ميتا أبلغت حتى 2026-09-27 فقط.')
    expect(coverageNote(truncated, false, (c) => (c === 'meta' ? 'Meta' : c))).toBe('This figure is incomplete: Meta reported through 2026-09-27 only.')
  })

  it('has no single date to state when the partial contributors disagree', () => {
    const two: Coverage = { ...truncated, excluded_contributors: ['meta', 'tiktok'], partial_contributors: ['meta', 'tiktok'], reported_through: { meta: '2026-09-27', tiktok: '2026-09-30' } }
    expect(reportedThrough(two)).toBeNull()
    expect(truncatedOnly(two)).toBe(true)
  })
})

/*
 * ANALYTICS-COVERAGE-COMPARABILITY-001 — two windows compare only when both are whole.
 */
describe('whether two windows may be compared', () => {
  const complete: Coverage = { state: 'complete', expected_contributors: ['meta'], included_contributors: ['meta'] }
  const truncated: Coverage = {
    state: 'partial', expected_contributors: ['meta'], excluded_contributors: ['meta'],
    partial_contributors: ['meta'], reported_through: { meta: '2026-09-27' },
  }

  it('compares two complete, populated windows', () => {
    expect(comparableWindows({ previous_rows_in_scope: true, current: { coverage: complete }, previous: { coverage: complete } })).toBe(true)
    expect(windowsUnlikeNote({ previous_rows_in_scope: true, current: { coverage: complete }, previous: { coverage: complete } }, true)).toBeNull()
  })

  it('refuses when the previous window holds no rows, and says so with its dates', () => {
    const s = { previous_rows_in_scope: false, previous_range: { from: '2026-08-12', to: '2026-09-10' }, current: { coverage: complete }, previous: { coverage: complete } }
    expect(comparableWindows(s)).toBe(false)
    expect(windowsUnlikeNote(s, false)).toBe('The previous period (2026-08-12 → 2026-09-10) holds no data, so there is nothing for this one to be measured against.')
  })

  it('refuses when the current window is truncated, naming who stopped and through when', () => {
    const s = { previous_rows_in_scope: true, current: { coverage: truncated }, previous: { coverage: complete } }
    expect(comparableWindows(s)).toBe(false)
    expect(windowsUnlikeNote(s, true, (c) => (c === 'meta' ? 'ميتا' : c)))
      .toBe('هذه الفترة مغطاة حتى 2026-09-27 فقط (ميتا لم تُبلّغ بعدها)، فالفترتان غير متكافئتين ولا تُقاس إحداهما على الأخرى.')
  })

  it('refuses when the PREVIOUS window is the incomplete one', () => {
    const s = { previous_rows_in_scope: true, current: { coverage: complete }, previous: { coverage: { ...truncated, partial_contributors: [], stale_contributors: ['meta'] } } }
    expect(comparableWindows(s)).toBe(false)
    expect(windowsUnlikeNote(s, false)).toMatch(/^The previous period is incomplete \(meta\)/)
  })

  it('treats a summary that states no coverage as comparable (the backend said nothing was missing)', () => {
    expect(comparableWindows({ previous_rows_in_scope: true, current: {}, previous: {} })).toBe(true)
  })
})
