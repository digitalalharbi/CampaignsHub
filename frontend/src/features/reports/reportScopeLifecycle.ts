/**
 * REPORT-SCOPE-SELECTION-001 — which campaigns a report about a PERIOD can cover.
 *
 * The builder listed every campaign a project has ever had, flat and ordered by name. An operator
 * building a report for last July had to recognise, from a name, which of two hundred campaigns were
 * running last July.
 *
 * The obvious fix — filter by `status === 'active'` — is the one the requirement forbids in as many
 * words: **a campaign inactive today may have run through the entire window being reported on**, and
 * excluding it silently removes real spend from a client's report. Nothing in the output would say a
 * campaign had been left out.
 *
 * So the question is answered by the window. `last_active_on` is the last day the campaign reported a
 * positive figure inside it, resolved by the backend for the period actually asked about — a day of
 * zeros is not a day it ran.
 *
 * Nothing is ever hidden. The groups decide ORDER and heading, not membership: a picker that drops a
 * campaign is worse than one that sorts it badly, because the operator cannot tell it happened.
 */
export interface ScopeCampaign {
  id: string
  name: string
  status: string | null
  /** The last day inside the report's window this campaign reported anything. Null = it did not. */
  last_active_on: string | null
}

export interface LifecycleGroups {
  /** Ran inside the window — whatever its status is today. */
  ran: ScopeCampaign[]
  /** Did not run inside it. Still selectable, still listed. */
  didNotRun: ScopeCampaign[]
  /** False when no period was asked about, so no claim is made either way. */
  periodKnown: boolean
}

/** Most recently active first, then by name, then by id — an order that never depends on chance. */
const byRecency = (a: ScopeCampaign, b: ScopeCampaign): number =>
  (b.last_active_on ?? '').localeCompare(a.last_active_on ?? '') ||
  a.name.localeCompare(b.name) ||
  a.id.localeCompare(b.id)

const byName = (a: ScopeCampaign, b: ScopeCampaign): number =>
  a.name.localeCompare(b.name) || a.id.localeCompare(b.id)

export function groupByLifecycle(
  campaigns: ScopeCampaign[],
  { periodKnown = true }: { periodKnown?: boolean } = {},
): LifecycleGroups {
  /*
   * With no period asked about, everything sits together. Sorting campaigns into «did not run» for a
   * window nobody named would be a claim about a period that does not exist.
   */
  if (!periodKnown) {
    return { ran: [], didNotRun: [...campaigns].sort(byName), periodKnown: false }
  }

  return {
    ran: campaigns.filter((c) => c.last_active_on !== null).sort(byRecency),
    didNotRun: campaigns.filter((c) => c.last_active_on === null).sort(byName),
    periodKnown: true,
  }
}
