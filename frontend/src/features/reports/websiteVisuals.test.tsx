import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { AcquisitionVisual, ClassificationVisual, CompactTiles, direction, GapStory, gapSegments, Journey, TablesVisual } from './WebsiteVisuals'
import type { StoreKpi } from '@/features/analytics/ga4/storeTypes'
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

describe('the website visuals of WEBSITE-VISUAL-002', () => {
  it('draws the journey: one order, read by the platform and by the website, and the four reasons as icons', () => {
    renderWithProviders(<Journey ar platforms={['snapchat', 'meta', 'snapchat']} />, { locale: 'ar' })
    expect(screen.getByTestId('journey-platform')).toHaveTextContent('طلب من الإعلان')
    expect(screen.getByTestId('journey-site')).toHaveTextContent('مباشر')
    expect(screen.getByTestId('journey-both')).toHaveTextContent('قراءتان صحيحتان')
    expect(within(screen.getByTestId('journey-why')).getAllByRole('listitem')).toHaveLength(4)
  })

  it('draws the visit as four large cards and sales and marketing as compact groups', () => {
    const k = (key: string, current: number): StoreKpi => ({ key, current, previous: null, change_pct: null, kind: 'count' } as unknown as StoreKpi)
    renderWithProviders(<CompactTiles ar={false} kpis={['revenue', 'sessions', 'checkouts', 'bounce_rate', 'transactions', 'users_daily_sum', 'engagement_rate'].map((x, i) => k(x, i + 1))} />, { locale: 'en' })
    const primary = Array.from(document.querySelectorAll('[data-tier="primary"]')).map((n) => n.getAttribute('data-testid'))
    expect(primary).toEqual(['ga4-tile-sessions', 'ga4-tile-users_daily_sum', 'ga4-tile-bounce_rate', 'ga4-tile-engagement_rate'])
    expect(within(screen.getByTestId('ga4-tiles-sales')).getByTestId('ga4-tile-transactions')).toBeInTheDocument()
    expect(within(screen.getByTestId('ga4-tiles-marketing')).getByTestId('ga4-tile-checkouts')).toBeInTheDocument()
  })

  it('draws channels as tiles and the leading device large, with the visits total and the coverage chips', () => {
    renderWithProviders(<AcquisitionVisual ar={false} funnel={[]}
      channels={[{ key: 'Direct', sessions: 60, purchases: null, revenue: null, revenue_usd: null, share: null }, { key: 'Paid Social', sessions: 40, purchases: null, revenue: null, revenue_usd: null, share: null }]}
      devices={[{ key: 'mobile', sessions: 90, purchases: null, revenue: null, revenue_usd: null, share: null }, { key: 'desktop', sessions: 10, purchases: null, revenue: null, revenue_usd: null, share: null }]}
      channelsMeta={{ total: 100, classified: 100, unclassified: 0, over: 7, days_covered: 7, days_total: 7 }} />, { locale: 'en' })
    expect(screen.getByTestId('ga4-channels')).toHaveAttribute('data-slices', '2')
    expect(screen.getByTestId('ga4-channel-Direct')).toHaveTextContent('60')
    expect(screen.getByTestId('ga4-channels-over')).toHaveTextContent('+7')
    expect(screen.getByTestId('ga4-device-mobile')).toHaveTextContent('90%')
    expect(screen.getByTestId('ga4-devices-total')).toHaveTextContent('100')
  })

  it('draws the classification and the products as bars, their tables one click away', () => {
    const rows = [{ label: 'Direct', sessions: 50, purchases: 5, revenue_usd: 100, conversion_rate: 0.1 }, { label: 'Paid Social', sessions: 30, purchases: 1, revenue_usd: 20, conversion_rate: 0.03 }]
    renderWithProviders(<ClassificationVisual ar={false} groups={{ channel: { rows }, platform: { rows: rows.slice(0, 1) } }} table={(g) => <span data-testid="the-table">{g}</span>} />, { locale: 'en' })
    expect(within(screen.getByTestId('ga4-classification-bars')).getAllByRole('listitem')).toHaveLength(2)
    fireEvent.click(screen.getByTestId('ga4-classification-tab-platform'))
    expect(within(screen.getByTestId('ga4-classification-bars')).getAllByRole('listitem')).toHaveLength(1)
    expect(screen.getByTestId('the-table')).toHaveTextContent('platform')
  })

  it('draws a products-only table without tabs, under its own title', () => {
    renderWithProviders(<TablesVisual testId="ga4-products-visual" title="Products" ar={false} currency="SAR"
      tables={{ products: { total: 2, rows: [{ name: 'Ring', views: 10, carts: 3, purchased: 2, revenue: 200, revenue_usd: null }, { name: 'Beads', views: 5, carts: 1, purchased: 1, revenue: 50, revenue_usd: null }] } }} />, { locale: 'en' })
    expect(screen.getByTestId('ga4-products-visual')).toHaveTextContent('Products')
    expect(screen.queryByTestId('ga4-table-tab-products')).toBeNull()
    expect(within(screen.getByTestId('ga4-table-bars')).getAllByRole('listitem')[0]).toHaveTextContent('Ring')
  })
})
