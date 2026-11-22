import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { AcquisitionVisual, ClassificationVisual, CompactTiles, direction, fingerprintRows, GapStory, gapSegments, Journey, orderPaths, SiteEvidence, TablesVisual } from './WebsiteVisuals'
import type { MatchPayload } from '@/features/analytics/ga4/matchTypes'
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

/** Razzah Avenue, 15–21 Nov 2026, as the live link's attribution part states it. */
const side = (s: number, o: number) => ({ sessions: s, purchases: o, revenue: null, revenue_usd: null, aov_usd: null, conversion_rate: null })
const razzahMatch = {
  state: 'ready', basis: 'ga4', order_level: false,
  platforms: [
    { platform: 'google', claimed: { conversions: 40, revenue_usd: null }, ga4: side(1969, 32), matched_share: null },
    { platform: 'meta', claimed: { conversions: 17, revenue_usd: null }, ga4: side(1158, 1), matched_share: null },
    { platform: 'openai_ads', claimed: { conversions: 3, revenue_usd: null }, ga4: null, matched_share: null },
    { platform: 'snapchat', claimed: { conversions: 90, revenue_usd: null }, ga4: side(6670, 6), matched_share: null },
    { platform: 'tiktok', claimed: { conversions: 64, revenue_usd: null }, ga4: side(995, 6), matched_share: null },
  ],
  other_paid: { ga4: side(646, 1) },
  unattributed: { sessions: 13311, classes: [{ class: 'organic', ...side(162, 3) }, { class: 'direct', ...side(9298, 42) }, { class: 'referral', ...side(2971, 11) }, { class: 'other', ...side(234, 1) }] },
  evidence: { click_id_sessions: [{ family: 'meta', sessions: 1396 }, { family: 'snapchat', sessions: 7049 }, { family: 'tiktok', sessions: 6227 }], id_pairs: 46 },
} as unknown as MatchPayload

describe('the website’s own evidence (WEBSITE-VISUAL-003)', () => {
  it('sets the visits labelled as each platform beside the visits that arrived with its click id', () => {
    expect(fingerprintRows(razzahMatch)).toEqual([
      { platform: 'snapchat', labelled: 6670, fingerprint: 7049 },
      { platform: 'tiktok', labelled: 995, fingerprint: 6227 },
      { platform: 'meta', labelled: 1158, fingerprint: 1396 },
    ])
  })

  it('splits the website’s orders by the path it credited, adding up to its own 103', () => {
    const paths = orderPaths(razzahMatch)
    expect(paths).toEqual([{ key: 'ads', orders: 45 }, { key: 'other_paid', orders: 1 }, { key: 'organic', orders: 3 }, { key: 'direct', orders: 42 }, { key: 'referral', orders: 11 }, { key: 'other', orders: 1 }])
    expect(paths.reduce((a, p) => a + p.orders, 0)).toBe(103)
  })

  it('draws the fingerprint bars with the multiple, and one dot per order', () => {
    renderWithProviders(<SiteEvidence data={razzahMatch} ar={false} />, { locale: 'en' })
    expect(screen.getByTestId('evidence-times-tiktok')).toHaveTextContent('×6.3')
    expect(screen.queryByTestId('evidence-times-snapchat')).toBeNull()
    expect(screen.getByTestId('evidence-waffle')).toHaveAttribute('data-dots', '103')
    expect(screen.getByTestId('evidence-orders-total')).toHaveTextContent('103')
    expect(screen.getByTestId('evidence-path-direct')).toHaveTextContent('42')
  })

  it('draws nothing without evidence or orders', () => {
    const { container } = renderWithProviders(<SiteEvidence data={{ state: 'ready', basis: 'ga4', order_level: false, platforms: [] } as unknown as MatchPayload} ar />, { locale: 'ar' })
    expect(container.querySelector('[data-testid="site-evidence"]')).toBeNull()
  })
})
