import { describe, expect, it } from 'vitest'
import { formatMix } from './formatMix'
import type { FormatRow } from './api'

/**
 * CONTENT-SUMMARY-COMPACT-001 — a share over an incomplete total overstates itself.
 *
 * The server already refuses `share_of_spend_not_on_the_leading_format` when any format withheld its
 * spend, for exactly this reason. A bar chart is a share by another name, so it inherits the rule: a
 * format whose money the contract could not add cannot sit in a denominator, and dividing by a total
 * missing one of its parts inflates every other slice by precisely the amount nobody can see.
 */
const row = (format: string, spend: number | null, creatives = 3): FormatRow => ({
  format,
  value: 1,
  spend,
  creatives,
})

describe('the mix of spend across content formats', () => {
  it('divides each format by the comparable total', () => {
    const mix = formatMix([row('video', 750), row('image', 250)])

    expect(mix.shares.map((s) => [s.format, s.share])).toEqual([['video', 0.75], ['image', 0.25]])
    expect(mix.total).toBe(1000)
  })

  it('reads largest first, because that is the question', () => {
    expect(formatMix([row('image', 100), row('video', 900)]).shares[0].format).toBe('video')
  })

  /**
   * A withheld format is NAMED, never dropped and never folded into the denominator.
   *
   * Dropping it would make the remaining slices add to 100% of a total that is not the whole spend —
   * a bar that looks complete and is not.
   */
  it('names a format whose spend could not be added, and leaves it out of the total', () => {
    const mix = formatMix([row('video', 400), row('carousel', null)])

    expect(mix.shares.map((s) => s.format)).toEqual(['video'])
    expect(mix.withheld).toEqual(['carousel'])
    expect(mix.total, 'an unpriceable format was counted in the denominator').toBe(400)
  })

  /** Nothing comparable is no mix at all — not an empty bar implying everything is zero. */
  it('states no shares when nothing can be priced', () => {
    const mix = formatMix([row('video', null), row('image', null)])

    expect(mix.shares).toEqual([])
    expect(mix.withheld).toEqual(['video', 'image'])
  })

  /** A format that ran and spent nothing is not a slice — a zero-width bar is noise. */
  it('leaves a format that spent nothing out of the bar', () => {
    expect(formatMix([row('video', 500), row('image', 0)]).shares.map((s) => s.format)).toEqual(['video'])
  })

  it('has nothing to say about an empty library', () => {
    expect(formatMix(undefined)).toEqual({ shares: [], withheld: [], total: 0 })
  })
})
