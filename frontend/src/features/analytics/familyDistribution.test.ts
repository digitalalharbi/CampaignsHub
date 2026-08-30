import { describe, expect, it } from 'vitest'
import { distributionFor } from './familyDistribution'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — «where is the budget concentrated», inside one family.
 *
 * The rule this shares with the platform paths, one level down: a share is OF THE FAMILY. «40% of
 * sales» is a decision somebody made; «40% of everything» is the mix, and reading the second as
 * concentration makes a small family look concentrated and a large one look spread.
 */
const c = (name: string, spend: number) => ({ name, spend })

describe('a family’s distribution', () => {
  it('shares out of the family’s own spend', () => {
    const d = distributionFor([c('A', 6000), c('B', 3000), c('C', 1000)])

    expect(d.total).toBe(10_000)
    expect(d.slices.map((s) => s.share)).toEqual([0.6, 0.3, 0.1])
  })

  /** A single bar at 100% is the shape of an answer to a question nobody asked. */
  it('refuses to call one campaign a distribution', () => {
    expect(distributionFor([c('Only', 5000)]).meaningful).toBe(false)
    expect(distributionFor([]).meaningful).toBe(false)
  })

  /** A campaign that spent nothing is not a slice of a spend distribution. */
  it('leaves out what did not spend', () => {
    const d = distributionFor([c('A', 100), c('B', 0), c('C', 100)])

    expect(d.slices).toHaveLength(2)
    expect(d.total).toBe(200)
  })

  it('names the largest and groups the tail', () => {
    const d = distributionFor([c('A', 50), c('B', 30), c('C', 10), c('D', 6), c('E', 4)])

    expect(d.slices.map((s) => s.name)).toEqual(['A', 'B', 'C'])
    expect(d.rest?.spend).toBe(10)
  })

  /**
   * «Other» standing for ONE campaign hides a name the reader was entitled to.
   */
  it('does not hide a single campaign behind «other»', () => {
    const d = distributionFor([c('A', 50), c('B', 30), c('C', 10), c('D', 10)])

    expect(d.rest).toBeNull()
    expect(d.slices).toHaveLength(3)
  })
})
