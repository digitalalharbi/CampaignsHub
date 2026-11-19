import { describe, expect, it } from 'vitest'
import { lastSuccessfulSync } from './syncTruth'

describe('lastSuccessfulSync — ANALYTICS-SYNC-TRUTH-001', () => {
  it('a quiet account that synced successfully has synced: zero spend is data, not a gap', () => {
    expect(lastSuccessfulSync([{ last_success_at: '2026-11-19T08:00:00+00:00', data_freshness_at: null }])).toBe('2026-11-19T08:00:00+00:00')
  })

  it('takes the newest of the run and the stored figure, across every source', () => {
    expect(lastSuccessfulSync([
      { last_success_at: '2026-11-18T08:00:00+00:00', data_freshness_at: '2026-11-17T08:00:00+00:00' },
      { last_success_at: null, data_freshness_at: '2026-11-19T01:00:00+00:00' },
    ])).toBe('2026-11-19T01:00:00+00:00')
  })

  it('an older install without the field still reads the stored figure', () => {
    expect(lastSuccessfulSync([{ data_freshness_at: '2026-11-10T00:00:00+00:00' }])).toBe('2026-11-10T00:00:00+00:00')
  })

  it('says null only when nothing ever synced', () => {
    expect(lastSuccessfulSync([])).toBeNull()
    expect(lastSuccessfulSync([{ last_success_at: null, data_freshness_at: null }])).toBeNull()
  })
})
