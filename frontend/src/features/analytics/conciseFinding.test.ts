import { describe, expect, it } from 'vitest'

import { conciseFinding } from './conciseFinding'
import type { DiagnosticInput } from './diagnose'

/**
 * The dashboard's single line, and the two ways a headline lies.
 *
 * It reports the wrong thing — «no conversions» to somebody whose ads never ran, sending them to the
 * wrong end of their own funnel. Or it reports anything at all about an account it could not read,
 * which is the failure the panel version exists to prevent, made worse by being the first sentence on
 * the screen.
 */
const input = (o: Partial<DiagnosticInput> = {}): DiagnosticInput => ({
  objective: 'sales',
  totals: { spend: 1000, impressions: 100000, clicks: 500, landing_page_views: 400, conversions: 10, revenue: 5000 },
  reported: { spend: true, impressions: true, clicks: true, landing_page_views: true, conversions: true, revenue: true },
  ...o,
})

describe('the concise dashboard finding', () => {
  /**
   * Two findings at once, and the earlier stage wins.
   *
   * A low click-through and no conversions are both true here, and `diagnose` returns them in that
   * order — attraction, then conversion. Reporting «no conversions» to somebody whose ads are barely
   * being clicked sends them to the wrong end of their own funnel. The fixture deliberately produces
   * BOTH, because a single-finding fixture cannot tell «earliest» from «last» and the first version
   * of this test could not either.
   */
  it('reports the earliest weakness along the journey, not the last one found', () => {
    const f = conciseFinding(input({
      totals: { spend: 1000, impressions: 100000, clicks: 10, landing_page_views: 8, conversions: 0, revenue: 0 },
    }))

    expect(f?.code).toBe('weak_attraction')
  })

  /** And with only one finding it still reports that one. */
  it('reports a lone weakness', () => {
    const f = conciseFinding(input({
      totals: { spend: 1000, impressions: 0, clicks: 0, landing_page_views: 0, conversions: 0, revenue: 0 },
    }))

    expect(f?.code).toBe('not_delivering')
  })

  it('says nothing when the account could not be examined', () => {
    expect(conciseFinding(input({ reported: { spend: true } }))).toBeNull()
  })

  it('says nothing when nothing is wrong', () => {
    expect(conciseFinding(input())).toBeNull()
  })

  /** It reads the same engine, so an inference stays an inference in the headline too. */
  it('carries the confidence through rather than flattening it', () => {
    const f = conciseFinding(input({ totals: { ...input().totals, clicks: 10 } }))

    expect(f?.confidence).toBe('probable')
  })
})
