import { describe, expect, it } from 'vitest'

import { LIFECYCLE_KEYS, lifecycleCounts, lifecycleView, type LifecycleInput } from './campaignLifecycleView'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «active campaigns default, inactive accessible».
 *
 * The campaigns workspace listed every campaign a project has ever had, newest first, so a project
 * with two years of history opened on whatever happened to be created last. An operator's first
 * question is what is running now, and the answer was somewhere in the list.
 *
 * Two rules this must not break while fixing that:
 *
 *   1. **Inactive is never hidden.** It is one click away and its count is on screen, because a
 *      campaign silently missing from a list is worse than one sorted low.
 *   2. **A view that cannot be computed is not «nothing is active».** Relevance needs the metrics
 *      window; when it has not arrived, or failed, defaulting to «active only» would present an
 *      empty page as a fact about the account. It falls back to «all» and says why.
 */
const c = (o: Partial<LifecycleInput> = {}): LifecycleInput => ({
  id: 'c',
  status: 'active',
  last_active_on: '2026-07-30',
  spend: 100,
  ...o,
})

const END = '2026-07-31'

describe('the lifecycle a campaign is in', () => {
  it('separates what is running from what has stopped, and keeps both', () => {
    const rows = [
      c({ id: 'running', status: 'active', last_active_on: '2026-07-31' }),
      c({ id: 'stopped', status: 'paused', last_active_on: '2026-07-02' }),
      c({ id: 'dark', status: 'active', last_active_on: null }),
    ]

    const view = lifecycleView(rows, { lifecycle: 'active', windowEnd: END, metricsKnown: true })

    // «Active» is what is RUNNING — serving and switched-on-but-dark alike, because both are a
    // campaign an operator still owns this month.
    expect(view.rows.map((r) => r.id)).toEqual(['running', 'dark'])
    expect(view.applied).toBe('active')
  })

  it('offers the stopped ones on their own, and all of them together', () => {
    const rows = [c({ id: 'a', status: 'active' }), c({ id: 'b', status: 'completed' })]

    expect(lifecycleView(rows, { lifecycle: 'inactive', windowEnd: END, metricsKnown: true }).rows.map((r) => r.id)).toEqual(['b'])
    expect(lifecycleView(rows, { lifecycle: 'all', windowEnd: END, metricsKnown: true }).rows).toHaveLength(2)
  })

  it('counts every lifecycle whatever is being shown, so nothing is silently missing', () => {
    const rows = [
      c({ id: 'a', status: 'active', last_active_on: '2026-07-31' }),
      c({ id: 'b', status: 'active', last_active_on: null }),
      c({ id: 'x', status: 'archived' }),
      c({ id: 'y', status: 'paused' }),
    ]

    expect(lifecycleCounts(rows, END, true)).toEqual({ active: 2, inactive: 2, all: 4 })
  })

  /*
   * The claim that must never be made by accident: «nothing is running».
   *
   * Relevance is read from the metrics window. Before it arrives — or when it failed — every
   * campaign looks dark, and «active only» would render an empty workspace as a statement about the
   * account rather than about a request that has not answered.
   */
  it('shows everything, and says relevance is unavailable, when the metrics are not known', () => {
    const rows = [c({ id: 'a' }), c({ id: 'b', status: 'completed' })]

    const view = lifecycleView(rows, { lifecycle: 'active', windowEnd: END, metricsKnown: false })

    expect(view.rows).toHaveLength(2)
    expect(view.applied).toBe('all')
    expect(view.degraded).toBe(true)
  })

  it('is not degraded once the metrics are known', () => {
    expect(lifecycleView([c()], { lifecycle: 'active', windowEnd: END, metricsKnown: true }).degraded).toBe(false)
  })

  /*
   * A project whose campaigns have all finished is a REAL empty active view — the operator should
   * see «nothing is running» and the inactive count beside it, not be silently shown the history as
   * though it were live.
   */
  it('leaves a genuinely empty active view empty, with the inactive count to explain it', () => {
    const rows = [c({ id: 'x', status: 'completed' }), c({ id: 'y', status: 'archived' })]

    const view = lifecycleView(rows, { lifecycle: 'active', windowEnd: END, metricsKnown: true })

    expect(view.rows).toEqual([])
    expect(view.degraded).toBe(false)
    expect(view.counts.inactive).toBe(2)
  })

  it('orders what it shows by the shared relevance rule, not by whatever order it was given', () => {
    const rows = [
      c({ id: 'dark', status: 'active', last_active_on: null, spend: 9000 }),
      c({ id: 'serving-small', status: 'active', last_active_on: '2026-07-31', spend: 5 }),
    ]

    const view = lifecycleView(rows, { lifecycle: 'active', windowEnd: END, metricsKnown: true })

    // Serving outranks dark even on a fraction of the spend — the dark one is the problem, not the
    // headline.
    expect(view.rows.map((r) => r.id)).toEqual(['serving-small', 'dark'])
  })

  it('offers exactly the three choices, in the order they are read', () => {
    expect(LIFECYCLE_KEYS).toEqual(['active', 'inactive', 'all'])
  })
})
