import { describe, expect, it } from 'vitest'
import {
  groupByTime,
  notificationScope,
  scopeLabel,
  severityTone,
  timeBucket,
} from './notificationPresentation'

/**
 * UX-NOTIFICATION-CARD-001 — the two facts the centre was throwing away.
 *
 * Severity survived exactly until somebody read the row: the dot was set to `bg-transparent` on read,
 * so a read critical alert and a read info note were the same object on screen. And scope was never
 * shown at all, although `project_id === null` already meant «the whole portfolio».
 */
describe('a notification row', () => {
  it('keeps its severity after it has been read', () => {
    // The tone is a function of severity ALONE. A read row may be quieter; it may not become unlabelled.
    expect(severityTone('critical')).toBe('danger')
    expect(severityTone('warning')).toBe('warning')
    expect(severityTone('success')).toBe('success')
    expect(severityTone('info')).toBe('info')
  })

  /** `project_id === null` is the portfolio — the same rule the API resource states. */
  it('says whether it is about one project or all of them', () => {
    expect(notificationScope({ project_id: null })).toBe('portfolio')
    expect(notificationScope({ project_id: 'p-1' })).toBe('project')

    expect(scopeLabel('portfolio', false)).toBe('All projects')
    expect(scopeLabel('portfolio', true)).toBe('كل المشاريع')
  })
})

describe('time grouping', () => {
  /*
   * Built from LOCAL components, not a `Z` string.
   *
   * «Today» means the reader's today, so the buckets are local-day boundaries — and a fixture written
   * as `2026-09-25T23:00:00Z` therefore lands on a different day depending on where the suite runs.
   * The first version of this test did exactly that and failed on a machine east of UTC, which is the
   * test being wrong about timezones rather than the rule being wrong about days.
   */
  const now = new Date(2026, 8, 26, 10, 0, 0)
  const at = (day: number, hour: number) => new Date(2026, 8, day, hour, 0, 0).toISOString()

  it('puts a row in the bucket a reader would scan for', () => {
    expect(timeBucket(at(26, 9), now)).toBe('today')
    expect(timeBucket(at(25, 23), now)).toBe('yesterday')
    expect(timeBucket(at(1, 10), now)).toBe('earlier')
  })

  /** A row whose date never arrived is not «today», and guessing would put an unknown age on top. */
  it('treats a missing or unreadable date as undated rather than now', () => {
    expect(timeBucket(null, now)).toBe('undated')
    expect(timeBucket(undefined, now)).toBe('undated')
    expect(timeBucket('not a date', now)).toBe('undated')
  })

  /** Fixed order: headings that move on every refresh are headings nobody can learn. */
  it('returns the buckets in reading order and omits the empty ones', () => {
    const groups = groupByTime([
      { created_at: at(1, 10) },
      { created_at: at(26, 9) },
      { created_at: null },
    ], now)

    expect(groups.map((g) => g.bucket)).toEqual(['today', 'earlier', 'undated'])
    expect(groups.every((g) => g.items.length > 0)).toBe(true)
  })

  it('keeps every row it was given', () => {
    const items = [
      { created_at: at(26, 1) },
      { created_at: at(26, 2) },
      { created_at: at(25, 2) },
    ]

    expect(groupByTime(items, now).reduce((n, g) => n + g.items.length, 0)).toBe(items.length)
  })
})
