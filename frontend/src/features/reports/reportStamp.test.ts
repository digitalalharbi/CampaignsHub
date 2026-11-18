import { describe, expect, it } from 'vitest'
import { reportStamp } from './reportFooter'

/** REPORT-FRESHNESS-DATE-001 — a late platform holds the report's date back to its oldest confirmation. */
describe('the report date while a platform is late', () => {
  const generated = '2026-11-17T17:46:00Z'
  const oldest = '2026-11-17T13:40:00Z'

  it('is the oldest confirmation when a platform is not confirmed fresh', () => {
    expect(reportStamp({ generated_at: generated, oldest_success_at: oldest, worst_status: 'delayed' })).toBe(oldest)
    expect(reportStamp({ generated_at: generated, oldest_success_at: oldest, worst_status: 'unknown' })).toBe(oldest)
  })

  it('is the computation time when every platform is fresh', () => {
    expect(reportStamp({ generated_at: generated, oldest_success_at: oldest, worst_status: 'fresh' })).toBe(generated)
  })

  it('never moves forward and states nothing without a summary', () => {
    expect(reportStamp({ generated_at: generated, oldest_success_at: '2026-11-18T00:00:00Z', worst_status: 'stale' })).toBe(generated)
    expect(reportStamp({ generated_at: generated, oldest_success_at: null, worst_status: 'stale' })).toBe(generated)
    expect(reportStamp(undefined)).toBeNull()
  })
})
