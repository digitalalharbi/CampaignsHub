import { describe, expect, it } from 'vitest'
import { firstSyncOutcome } from './firstSyncOutcome'
import type { AccountSyncRun } from './api'

/**
 * INTEGRATION-FIRST-SYNC-OUTCOME-001 — what the wizard is allowed to say happened.
 *
 * The rules that are easy to get subtly wrong live here rather than inside a polling component,
 * which is the only reason they can be asserted at all.
 */
const run = (over: Partial<AccountSyncRun>): AccountSyncRun => ({
  id: 'r', provider: 'meta', status: 'success', trigger: 'automatic',
  window_start: null, window_end: null, provider_rows: null, parsed_rows: null, mapped_rows: null,
  metrics_imported: 0, duration_seconds: null, attempts: 1,
  started_at: '2026-09-15T18:30:00Z', finished_at: null, error: null, repeats: 1, repeats_since: null,
  ...over,
})

const AT = Date.parse('2026-09-15T18:00:00Z')

describe('what the first sync did', () => {
  it('says queued when no run has started yet', () => {
    expect(firstSyncOutcome([], AT)).toEqual({ state: 'queued' })
    expect(firstSyncOutcome(undefined, AT)).toEqual({ state: 'queued' })
  })

  /**
   * The rule that stops the dialog reporting somebody else's success.
   *
   * An account bound to a second project already has history. Yesterday's four thousand rows are
   * not this button's outcome, and showing them would be the most convincing possible lie.
   */
  it('ignores runs that started before the connection was confirmed', () => {
    const older = run({ started_at: '2026-09-15T17:30:00Z', status: 'success', metrics_imported: 4000 })

    expect(firstSyncOutcome([older], AT)).toEqual({ state: 'queued' })
  })

  it('reports a run still going as running, not as no result', () => {
    expect(firstSyncOutcome([run({ status: 'running' })], AT)).toEqual({ state: 'running' })
    expect(firstSyncOutcome([run({ status: 'pending' })], AT)).toEqual({ state: 'running' })
  })

  it('reports what was imported', () => {
    expect(firstSyncOutcome([run({ status: 'success', metrics_imported: 936 })], AT))
      .toEqual({ state: 'imported', rows: 936 })
  })

  /**
   * A refusal carries the provider's own words — which, since the storage contract, is the whole
   * sentence with its identifiers rather than a truncation or a PostgreSQL error.
   */
  it('carries the provider’s reason when the run failed', () => {
    const reason = 'Meta Marketing API could not return daily insights: (#200) … · code 200 · trace A-Rz'

    expect(firstSyncOutcome([run({ status: 'failed', error: reason })], AT))
      .toEqual({ state: 'failed', reason })
  })

  /**
   * «Imported 0 rows» reads as a broken pipeline. «The platform reported nothing» reads as what it
   * is. Same run, different sentence, and only the second is actionable.
   */
  it('calls a successful run that imported nothing «no data», not zero', () => {
    expect(firstSyncOutcome([run({ status: 'success', metrics_imported: 0 })], AT)).toEqual({ state: 'no_data' })
    expect(firstSyncOutcome([run({ status: 'no_data' })], AT)).toEqual({ state: 'no_data' })
  })

  /** A retry supersedes the attempt before it — newest wins, not first-found. */
  it('answers with the newest run, not the first in the list', () => {
    const rows = [
      run({ id: 'old', started_at: '2026-09-15T18:10:00Z', status: 'failed', error: 'transient' }),
      run({ id: 'new', started_at: '2026-09-15T18:40:00Z', status: 'success', metrics_imported: 12 }),
    ]

    expect(firstSyncOutcome(rows, AT)).toEqual({ state: 'imported', rows: 12 })
    expect(firstSyncOutcome([...rows].reverse(), AT)).toEqual({ state: 'imported', rows: 12 })
  })

  /** A run with no start time cannot be placed in time, so it is not claimed as this one's. */
  it('ignores a run that never said when it started', () => {
    expect(firstSyncOutcome([run({ started_at: null, status: 'success', metrics_imported: 99 })], AT))
      .toEqual({ state: 'queued' })
  })
})
