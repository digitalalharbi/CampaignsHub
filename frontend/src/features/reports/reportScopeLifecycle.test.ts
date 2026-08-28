import { describe, expect, it } from 'vitest'

import { groupByLifecycle, type ScopeCampaign } from './reportScopeLifecycle'

/**
 * REPORT-SCOPE-SELECTION-001 — the builder groups by what was running IN THE WINDOW.
 *
 * The picker listed every campaign a project has ever had, flat and ordered by name. An operator
 * building a report for last July had to recognise, from a name, which of two hundred campaigns
 * were running last July.
 *
 * The obvious fix — filter by `status === 'active'` — is the one the requirement forbids in as many
 * words: a campaign inactive today may have run through the entire window being reported on, and
 * excluding it silently removes real spend from a client's report.
 *
 * So the grouping is answered by the window: `last_active_on` is the last day the campaign reported
 * a positive figure inside it, resolved by the backend for the period actually asked about.
 */
const c = (o: Partial<ScopeCampaign>): ScopeCampaign => ({
  id: 'x', name: 'X', status: 'active', last_active_on: null, ...o,
})

describe('grouping the campaigns a report can cover', () => {
  /* The one that matters: it stopped, and it still belongs in a report about when it ran. */
  it('puts a campaign that ran in the window under «ran in this period», however it ended', () => {
    const g = groupByLifecycle([c({ id: 'julyOnly', status: 'completed', last_active_on: '2026-07-14' })])

    expect(g.ran.map((x) => x.id)).toEqual(['julyOnly'])
    expect(g.didNotRun).toEqual([])
  })

  it('separates the ones that did not run in it, and never hides them', () => {
    const g = groupByLifecycle([
      c({ id: 'ran', last_active_on: '2026-07-30' }),
      c({ id: 'dark', status: 'active', last_active_on: null }),
      c({ id: 'archived', status: 'archived', last_active_on: null }),
    ])

    expect(g.ran.map((x) => x.id)).toEqual(['ran'])
    expect(g.didNotRun.map((x) => x.id)).toEqual(['archived', 'dark'])
    // Every campaign is still somewhere — a picker that drops one is worse than one that sorts badly.
    expect(g.ran.length + g.didNotRun.length).toBe(3)
  })

  /*
   * Within «ran», the most recently active first: an operator scanning a July report wants the
   * campaign that was live on the 30th before the one that stopped on the 2nd.
   */
  it('orders what ran by how recently it ran, then by name', () => {
    const g = groupByLifecycle([
      c({ id: 'early', name: 'A', last_active_on: '2026-07-02' }),
      c({ id: 'late', name: 'Z', last_active_on: '2026-07-30' }),
      c({ id: 'alsoLate', name: 'B', last_active_on: '2026-07-30' }),
    ])

    expect(g.ran.map((x) => x.id)).toEqual(['alsoLate', 'late', 'early'])
  })

  /** Deterministic: the same input always produces the same order, ties included. */
  it('is deterministic on ties', () => {
    const rows = [c({ id: 'b', name: 'Same' }), c({ id: 'a', name: 'Same' })]

    expect(groupByLifecycle(rows).didNotRun.map((x) => x.id)).toEqual(
      groupByLifecycle([...rows].reverse()).didNotRun.map((x) => x.id),
    )
  })

  /*
   * With no window asked about, nothing is claimed: everything sits together rather than being
   * sorted into «did not run», which would be a statement about a period nobody named.
   */
  it('makes no claim when the period was never asked about', () => {
    const g = groupByLifecycle([c({ id: 'a' }), c({ id: 'b' })], { periodKnown: false })

    expect(g.ran).toEqual([])
    expect(g.didNotRun).toHaveLength(2)
    expect(g.periodKnown).toBe(false)
  })
})
