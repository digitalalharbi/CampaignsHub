import { describe, expect, it } from 'vitest'

import { fmtClock, fmtDate, fmtDateTime } from './datetime'

/**
 * DATE-FORMAT-002 — a bare `toLocaleTimeString()` takes the READER's locale.
 *
 * Under Arabic that renders «3:40:04 م» — an Arabic AM/PM marker beside Latin digits, which is
 * exactly the garbling this module exists to prevent, and it was on the page whose whole job is to
 * state that the system is healthy.
 */
describe('canonical date and time formatting', () => {
  const noon = new Date('2026-08-25T15:40:04Z')

  it('renders a clock in 24h with no AM/PM marker in any language', () => {
    const out = fmtClock(noon)

    expect(out).toMatch(/^\d{2}:\d{2}:\d{2}$/)
    expect(out).not.toMatch(/[صم]/) // the Arabic AM/PM markers
    expect(out.toLowerCase()).not.toContain('am')
    expect(out.toLowerCase()).not.toContain('pm')
  })

  it('renders a date as YYYY-MM-DD', () => {
    expect(fmtDate('2026-08-25T00:00:00Z')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('renders a timestamp as YYYY-MM-DD HH:mm', () => {
    expect(fmtDateTime('2026-08-25T15:40:04Z')).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/)
  })

  it('says «—» for an absent or unparseable value rather than «Invalid Date»', () => {
    for (const bad of [null, undefined, '', 'not a date']) {
      expect(fmtClock(bad as never)).toBe('—')
      expect(fmtDate(bad as never)).toBe('—')
      expect(fmtDateTime(bad as never)).toBe('—')
    }
  })
})
