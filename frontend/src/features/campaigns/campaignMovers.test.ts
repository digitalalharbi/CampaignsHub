import { describe, expect, it } from 'vitest'
import { movers, type MoverRow } from './campaignMovers'

/**
 * «Which campaigns are deteriorating? Which are strongest?» — asked of MOVEMENT, not of level.
 *
 * The load-bearing case is the null one. `spend_change` is null when a campaign has no baseline to
 * move against, and sorting that as zero would rank «we have no idea» among real trends — the same
 * lie as a coalesced zero, one derivation further out.
 */
const row = (over: Partial<MoverRow> & { id: string }): MoverRow => ({
  name: over.id,
  spend: 1000,
  previous_spend: 1000,
  spend_change: 0,
  ...over,
})

describe('the campaigns that moved', () => {
  it('ranks the largest rises and the largest falls apart', () => {
    const out = movers([
      row({ id: 'up-small', spend_change: 0.1 }),
      row({ id: 'up-big', spend_change: 0.9 }),
      row({ id: 'down-big', spend_change: -0.8 }),
      row({ id: 'down-small', spend_change: -0.2 }),
      row({ id: 'flat', spend_change: 0 }),
    ])

    expect(out.up.map((r) => r.id)).toEqual(['up-big', 'up-small'])
    expect(out.down.map((r) => r.id)).toEqual(['down-big', 'down-small'])
  })

  /** A campaign that did not exist last period has not «not moved» — it cannot be ranked at all. */
  it('excludes a campaign with no baseline, and says how many it excluded', () => {
    const out = movers([
      row({ id: 'new', previous_spend: null, spend_change: null }),
      row({ id: 'from-zero', previous_spend: 0, spend_change: null }),
      row({ id: 'real', spend_change: 0.5 }),
    ])

    expect(out.up.map((r) => r.id)).toEqual(['real'])
    expect(out.down).toEqual([])
    expect(out.withoutBaseline).toBe(2)
  })

  /** A flat campaign is not a riser. Zero is a measurement, and it belongs in neither list. */
  it('leaves an unchanged campaign out of both lists', () => {
    const out = movers([row({ id: 'flat', spend_change: 0 })])

    expect(out.up).toEqual([])
    expect(out.down).toEqual([])
    expect(out.withoutBaseline).toBe(0)
  })

  /**
   * The limit bounds each DIRECTION, not the pair.
   *
   * A portfolio where everything rose should show a full list of risers and an empty fall list, not
   * five rows shared between them — which would silently hide the fifth riser to make room for a
   * fall that does not exist.
   */
  it('bounds each direction on its own', () => {
    const rising = Array.from({ length: 8 }, (_, i) => row({ id: `up-${i}`, spend_change: (i + 1) / 10 }))

    const out = movers(rising, 5)

    expect(out.up).toHaveLength(5)
    expect(out.up[0].id).toBe('up-7')
    expect(out.down).toEqual([])
  })

  /** Ties fall back to the money at stake, then the name — the order cannot reshuffle on refresh. */
  it('breaks a tie by spend, then by name', () => {
    const out = movers([
      row({ id: 'b', name: 'B', spend: 100, spend_change: 0.5 }),
      row({ id: 'a', name: 'A', spend: 100, spend_change: 0.5 }),
      row({ id: 'rich', name: 'Rich', spend: 9_000, spend_change: 0.5 }),
    ])

    expect(out.up.map((r) => r.name)).toEqual(['Rich', 'A', 'B'])
  })
})
