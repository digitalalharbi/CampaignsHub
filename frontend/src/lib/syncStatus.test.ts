import { describe, expect, it } from 'vitest'
import { SYNC_STATUSES, syncStatusLabel, syncStatusMeaning, syncStatusNeedsAttention } from './syncStatus'

/**
 * INTEG-RUNTIME §8 — the colours are the argument, so the colours are what is asserted.
 *
 * These are not label tests. Each one pins a decision that was wrong somewhere in the product before
 * this module existed: `no_data` painted as a problem, an unknown status painted as a success.
 */
describe('syncStatus', () => {
  it('is exactly the six words the pipeline may say', () => {
    expect([...SYNC_STATUSES]).toEqual([
      'running', 'success', 'no_data', 'partial_mapping', 'failed', 'awaiting_assignment',
    ])
  })

  /** A quiet weekend is not a fault. Amber here trains people to ignore amber everywhere. */
  it('never colours «no data» as a problem', () => {
    expect(syncStatusMeaning('no_data').tone).toBe('neutral')
    expect(syncStatusNeedsAttention('no_data')).toBe(false)
  })

  /** Rows the product could not place are figures missing from a client report. */
  it('asks for attention when rows could not be matched', () => {
    expect(syncStatusMeaning('partial_mapping').tone).toBe('warning')
    expect(syncStatusNeedsAttention('partial_mapping')).toBe(true)
  })

  /** An account nobody connected to a project is amber, not green and not red. */
  it('treats an unassigned account as work to do, not as a failure', () => {
    expect(syncStatusMeaning('awaiting_assignment').tone).toBe('warning')
    expect(syncStatusNeedsAttention('awaiting_assignment')).toBe(true)
  })

  /**
   * **The defect this replaced.** `AnalyticsPage` fell through to green for anything it did not
   * recognise, so an unknown status was reported to the customer as a success.
   */
  it('shows an unrecognised status verbatim and neutral, never as a success', () => {
    const meaning = syncStatusMeaning('something_new')

    expect(meaning.tone).toBe('neutral')
    expect(meaning.ar).toBe('something_new')
    expect(syncStatusNeedsAttention('something_new')).toBe(false)
  })

  it('answers in the reader’s language', () => {
    expect(syncStatusLabel('failed', true)).toBe('فشلت')
    expect(syncStatusLabel('failed', false)).toBe('Failed')
    expect(syncStatusLabel(null, true)).toBe('—')
  })
})
