import type { AccountSyncRun } from './api'

/**
 * INTEGRATION-FIRST-SYNC-OUTCOME-001 — «Connected. The first sync has started» was the last thing
 * the wizard ever said.
 *
 * The owner's integration journey ends `first sync → progress → success/failure → freshness`, and
 * the dialog stopped at the first of those. It announced a sync and then went quiet, whatever
 * happened next: a run that imported four thousand rows and a run refused by the provider left the
 * reader with the identical sentence and a dialog to close.
 *
 * That matters more now than it did last week. The storage contract means a refusal finally carries
 * the provider's own words — `(#200) Ad account owner has NOT grant ads_read…` — and the moment a
 * person most needs to read that is the moment they finished connecting.
 *
 * ## Why this is a function
 *
 * Deciding «what happened» from a list of runs is the part that is easy to get subtly wrong, and it
 * is untestable while it lives inside a polling component. The rules it encodes:
 *
 *  - only runs that STARTED after the connection was confirmed count. An account bound to a second
 *    project already has history, and yesterday's success is not this button's outcome.
 *  - a run still going is `running`, never «no result yet» — the difference is what the reader is
 *    waiting for.
 *  - `no_data` is its OWN outcome. A provider that answered honestly with nothing is not a failure,
 *    and calling it one teaches people to distrust a working connection.
 *  - no run at all is `queued`, which is the truth on a system whose worker has not picked it up —
 *    not a failure, and emphatically not a success.
 */
export type FirstSyncOutcome =
  | { state: 'queued' }
  | { state: 'running' }
  | { state: 'imported'; rows: number }
  | { state: 'no_data' }
  | { state: 'failed'; reason: string | null }

export function firstSyncOutcome(runs: AccountSyncRun[] | undefined, startedAfter: number): FirstSyncOutcome {
  const mine = (runs ?? []).filter((r) => {
    if (r.started_at === null) return false
    const at = Date.parse(r.started_at)

    return Number.isFinite(at) && at >= startedAfter
  })

  if (mine.length === 0) return { state: 'queued' }

  // Newest first: a retry's answer supersedes the attempt before it.
  const latest = [...mine].sort((a, b) => Date.parse(b.started_at ?? '') - Date.parse(a.started_at ?? ''))[0]

  if (latest.status === 'running' || latest.status === 'pending') return { state: 'running' }
  if (latest.status === 'failed') return { state: 'failed', reason: latest.error }
  if (latest.status === 'no_data') return { state: 'no_data' }

  /*
   * A success that imported nothing is reported as `no_data`, not as «0 imported».
   *
   * «Imported 0 rows» reads as a broken pipeline; «the platform reported nothing for this window»
   * reads as what it is. The two are the same run and a different sentence, and the second is the
   * one a person can act on.
   */
  return latest.metrics_imported > 0
    ? { state: 'imported', rows: latest.metrics_imported }
    : { state: 'no_data' }
}
