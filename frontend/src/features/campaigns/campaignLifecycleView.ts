import { campaignRelevance, orderByRelevance, type RelevanceRow } from './campaignRelevance'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «active campaigns default, inactive accessible».
 *
 * The workspace listed every campaign a project has ever had, newest first, so a project with two
 * years of history opened on whatever happened to be created last. An operator's first question is
 * what is running now, and the answer was somewhere in the list.
 *
 * The lifecycle is read through `campaignRelevance` — the same rule the ordering uses — rather than
 * a second definition of «active». `status === 'active'` on its own is the definition
 * REPORT-SCOPE-SELECTION-001 warns against: a campaign switched on and spending nothing is not the
 * same thing as one that is serving, and a campaign inactive today may have been running through the
 * whole window being reported on.
 *
 * Two rules this must not break:
 *
 *   1. **Inactive is never hidden.** It is one click away and its count is on screen, because a
 *      campaign silently missing from a list is worse than one sorted low.
 *   2. **A view that cannot be computed is not «nothing is active».** Relevance is read from the
 *      metrics window; before it arrives, or when it failed, every campaign looks dark. Defaulting
 *      to «active only» then would present an empty workspace as a fact about the account rather
 *      than about a request that has not answered. It falls back to «all» and reports that it did.
 */
export type Lifecycle = 'active' | 'inactive' | 'all'

export const LIFECYCLE_KEYS: Lifecycle[] = ['active', 'inactive', 'all']

/** A campaign, joined to the two facts relevance needs. `id` rather than `campaign_id` because this
 *  reads the campaign resource, which is what the workspace lists. */
export interface LifecycleInput extends Omit<RelevanceRow, 'campaign_id'> {
  id: string
}

export interface LifecycleCounts {
  active: number
  inactive: number
  all: number
}

export interface LifecycleView<T extends LifecycleInput> {
  rows: T[]
  /** What was actually applied — differs from what was asked when relevance is unavailable. */
  applied: Lifecycle
  /** True when relevance could not be computed and everything is being shown instead. */
  degraded: boolean
  counts: LifecycleCounts
}

/** «Active» is what an operator still owns this month: serving, and switched-on-but-dark alike. The
 *  dark one is not finished — it is the campaign most likely to need them. */
const isActive = (row: LifecycleInput, windowEnd: string): boolean =>
  campaignRelevance({ ...row, campaign_id: row.id }, windowEnd) !== 'stopped'

export function lifecycleCounts(rows: LifecycleInput[], windowEnd: string, metricsKnown: boolean): LifecycleCounts {
  if (!metricsKnown) return { active: 0, inactive: 0, all: rows.length }

  const active = rows.filter((r) => isActive(r, windowEnd)).length

  return { active, inactive: rows.length - active, all: rows.length }
}

export function lifecycleView<T extends LifecycleInput>(
  rows: T[],
  { lifecycle, windowEnd, metricsKnown }: { lifecycle: Lifecycle; windowEnd: string; metricsKnown: boolean },
): LifecycleView<T> {
  const counts = lifecycleCounts(rows, windowEnd, metricsKnown)

  if (!metricsKnown) {
    return { rows, applied: 'all', degraded: true, counts }
  }

  const kept = lifecycle === 'all' ? rows : rows.filter((r) => isActive(r, windowEnd) === (lifecycle === 'active'))

  /*
   * Ordered by the shared rule rather than by whatever order the list arrived in. A serving campaign
   * outranks a dark one even on a fraction of the spend: the dark one is the problem, not the
   * headline.
   */
  const ordered = orderByRelevance(
    kept.map((r) => ({ ...r, campaign_id: r.id })),
    windowEnd,
  ).map(({ campaign_id: _ignored, ...rest }) => rest as unknown as T)

  return { rows: ordered, applied: lifecycle, degraded: false, counts }
}
