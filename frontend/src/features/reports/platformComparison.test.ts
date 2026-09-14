import { describe, expect, it } from 'vitest'
import { platformComparisonRows } from './platformComparison'

/**
 * LIVE-CROSS-PLATFORM-001 — what the comparison may derive, and what it must refuse.
 *
 * The refusals matter more than the arithmetic. A cost per result over a WITHHELD spend reads as
 * free; over ZERO results it is a division by zero that prints «Infinity» on a client's report. Both
 * are figures this product does not have, and inventing either is worse than an empty cell — so they
 * are asserted absent rather than merely «not wrong».
 */
describe('the platform comparison rows', () => {
  it('names the platform rather than its database key', () => {
    const [row] = platformComparisonRows([{ provider: 'snapchat', spend: 10 }], 'en')

    expect(row.platform).not.toBe('snapchat')
    expect(String(row.platform).length).toBeGreaterThan(0)
  })

  it('derives cost per result from that platform own spend and results', () => {
    const [row] = platformComparisonRows([{ provider: 'meta', spend: 600, conversions: 30 }], 'en')

    expect(row.cost_per_result).toBe(20)
  })

  it('derives the click-through rate from that platform own clicks and impressions', () => {
    const [row] = platformComparisonRows([{ provider: 'meta', clicks: 90, impressions: 9000 }], 'en')

    expect(row.ctr).toBe(0.01)
  })

  it('refuses a cost per result where the platform reported no results', () => {
    const [row] = platformComparisonRows([{ provider: 'meta', spend: 600, conversions: 0 }], 'en')

    expect(row.cost_per_result).toBeNull()
  })

  /* FX-001: a withheld amount is null beside a preserved original — never a zero to divide by. */
  it('refuses a cost per result where the spend is withheld', () => {
    const [row] = platformComparisonRows([{ provider: 'meta', spend: null, conversions: 30 }], 'en')

    expect(row.cost_per_result).toBeNull()
    expect(row.spend).toBeNull()
  })

  it('refuses a rate where the platform reported no impressions', () => {
    const [row] = platformComparisonRows([{ provider: 'meta', clicks: 5, impressions: 0 }], 'en')

    expect(row.ctr).toBeNull()
  })

  /** A string where a figure belongs is not a figure — the payload is JSON somebody else produced. */
  it('treats a non-numeric value as absent rather than coercing it', () => {
    const [row] = platformComparisonRows([{ provider: 'meta', spend: '600' as unknown as number }], 'en')

    expect(row.spend).toBeNull()
  })

  it('keeps one row per platform, in the order the payload gave them', () => {
    const rows = platformComparisonRows(
      [{ provider: 'meta' }, { provider: 'tiktok' }, { provider: 'snapchat' }],
      'en',
    )

    expect(rows).toHaveLength(3)
  })
})
