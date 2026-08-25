import { describe, expect, it } from 'vitest'
import { SYNC_STATUSES, syncStatusLabel, syncStatusMeaning, syncStatusNeedsAttention } from './syncStatus'

/**
 * INTEG-RUNTIME §8 — the colours are the argument, so the colours are what is asserted.
 *
 * These are not label tests. Each one pins a decision that was wrong somewhere in the product before
 * this module existed: `no_data` painted as a problem, an unknown status painted as a success.
 */
describe('syncStatus', () => {
  /**
   * FRESHNESS-STATUS-001 — two vocabularies, both named.
   *
   * A sync RUN says how the last attempt went; a SOURCE says how current its data is. The list held
   * only the first, while `DataFreshnessService::verdict()` returns the second and the metrics
   * controller sends it as `last_sync_status` — so «fresh» reached the pill unmapped and printed as
   * itself, in English, on the tab about whether the numbers can be trusted.
   */
  it('is exactly the words the pipeline may say, from both vocabularies', () => {
    expect([...SYNC_STATUSES]).toEqual([
      // A sync run's outcome.
      'running', 'success', 'no_data', 'partial_mapping', 'failed', 'awaiting_assignment',
      // A source's freshness — `DataFreshnessService::verdict()`.
      'fresh', 'stale', 'awaiting_credentials',
    ])
  })

  it('gives every freshness verdict a label and a tone that matches its meaning', () => {
    // Current data is good news and reads as such; «stale» is the one that wants attention.
    expect(syncStatusMeaning('fresh').tone).toBe('success')
    expect(syncStatusNeedsAttention('fresh')).toBe(false)

    expect(syncStatusMeaning('stale').tone).toBe('warning')

    // A platform nobody has configured is not a fault, and amber here trains people to ignore amber.
    expect(syncStatusMeaning('awaiting_credentials').tone).toBe('neutral')
    expect(syncStatusNeedsAttention('awaiting_credentials')).toBe(false)

    for (const status of ['fresh', 'stale', 'awaiting_credentials']) {
      expect(syncStatusLabel(status, true)).not.toBe(status)
      expect(syncStatusLabel(status, false)).not.toBe(status)
    }
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
