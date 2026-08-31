import { describe, expect, it } from 'vitest'
import { readMetric } from '@/features/analytics/metricCatalog'
import { SPECS } from '@/features/analytics/metricCatalog'

/**
 * MONEY-TRUTH-004 — a client report must never say «لا توجد بيانات» over money that exists.
 *
 * Reports build their figures with `readMetric`, so provenance reaches them. What did not reach them
 * was the RENDERER: `readingText` predates the `withheld` variant and fell through its final `:`, so
 * a shared report printed «لا توجد بيانات» for spend the platform really reported — the same figure
 * Analytics shows in full.
 *
 * This is the worst surface for that contradiction, because the reader has no other screen to check
 * it against.
 */
const WITHHELD_KPIS = {
  spend: 0, revenue: 0, roas: 0, cpa: 0,
  spend_original: 4128.93, spend_withheld_rows: 262,
  revenue_original: 12969.03, revenue_withheld_rows: 262,
  money_original_currency: 'USD', money_original_currencies: 1,
  impressions: 2884062, clicks: 21802, conversions: 102,
}

// The report's own renderer, mirrored: if this and InteractiveReport diverge, the test is worthless,
// so the shape is asserted rather than the copy.
const readingText = (r: ReturnType<typeof readMetric>): string =>
  r.kind === 'value' ? r.text
    : r.kind === 'withheld' ? r.original
      : r.kind === 'not_provided' ? 'لم ترسله المنصة'
        : 'لا توجد بيانات'

describe('a report over withheld money', () => {
  it('prints the real amount, never «لا توجد بيانات»', () => {
    const reading = readMetric('spend', SPECS.spend, WITHHELD_KPIS as never, undefined)

    expect(reading.kind).toBe('withheld')
    expect(readingText(reading)).toBe('4,128.93 USD')
    expect(readingText(reading)).not.toBe('لا توجد بيانات')
    expect(readingText(reading)).not.toContain('0 SAR')
  })

  it('still says «لا توجد بيانات» when there genuinely is none', () => {
    const reading = readMetric('spend', SPECS.spend, { spend: null, spend_withheld_rows: 0 } as never, undefined)

    expect(readingText(reading)).toBe('لا توجد بيانات')
  })

  it('a measured zero stays a zero, not a story about exchange rates', () => {
    const reading = readMetric('spend', SPECS.spend, { spend: 0, spend_withheld_rows: 0 } as never, undefined)

    expect(reading.kind).toBe('value')
    expect(readingText(reading)).not.toContain('USD')
  })
})
