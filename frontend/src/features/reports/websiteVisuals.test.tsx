import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { direction, GapStory, gapSegments } from './WebsiteVisuals'
import type { GapReasonsPayload } from '@/features/analytics/ga4/matchTypes'

/** Razzah Avenue, 15–21 Nov 2026: platforms 214, website 103, parts +66 +103 0 −58. */
const razzah = {
  headline: { unit: 'conversions', claimed: 214, measured: 103, gap: 111, gap_share: 0.52, gap_usd: 7390, excluded_campaigns: 0 },
  reasons: [
    { key: 'no_trace', value: 66, share: 0.59 },
    { key: 'attribution_rule', value: 103, share: 0.93 },
    { key: 'untagged_channel', value: 0, share: 0 },
    { key: 'no_platform_trace', value: -58, share: 0.52 },
  ],
  unexplained: { key: 'unexplained', value: 0 },
} as unknown as GapReasonsPayload

const sum = (xs: Array<{ value: number }>) => xs.reduce((a, b) => a + b.value, 0)

describe('WEBSITE-VISUAL-001 — the gap as two aligned bars', () => {
  it('splits both counts into what they share and what each side adds, and both bars add up exactly', () => {
    const g = gapSegments(razzah)!
    expect(g.shared).toBe(45)
    expect(g.shared + sum(g.platform)).toBe(214)
    expect(g.shared + sum(g.site)).toBe(103)
    expect(g.platform.map((s) => s.key)).toEqual(['no_trace', 'attribution_rule'])
    expect(g.site).toEqual([{ key: 'no_platform_trace', value: 58 }])
  })

  it('draws what the shown parts leave unaccounted as «unexplained», never drops it', () => {
    const g = gapSegments({ ...razzah, reasons: [{ key: 'attribution_rule', value: 100, share: 0.9 }] } as unknown as GapReasonsPayload)!
    expect(g.shared + sum(g.platform)).toBe(214)
    expect(g.shared + sum(g.site)).toBe(103)
    expect(g.platform.some((s) => s.key === 'unexplained')).toBe(true)
  })

  it('draws nothing without both counts', () => {
    expect(gapSegments({ headline: { ...razzah.headline!, measured: null } } as unknown as GapReasonsPayload)).toBeNull()
  })

  it('pictures one order counted by several platforms, and names no raw key', () => {
    renderWithProviders(<GapStory data={razzah} ar platforms={['snapchat', 'tiktok', 'meta', 'google']} />, { locale: 'ar' })
    expect(screen.getByTestId('gap-one-order')).toHaveTextContent('×4')
    expect(screen.getByTestId('gap-bar-platforms-shared')).toHaveTextContent('45')
    expect(screen.getByTestId('gap-reasons').textContent).not.toMatch(/no_trace|attribution_rule|no_platform_trace/)
  })

  it('reads platform vs website as a neutral direction', () => {
    expect(direction(90, 6)).toBe('higher')
    expect(direction(22, 63)).toBe('lower')
    expect(direction(40, 36)).toBe('close')
    expect(direction(null, 3)).toBeNull()
  })
})
