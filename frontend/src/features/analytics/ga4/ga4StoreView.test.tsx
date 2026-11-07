import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { Ga4Acquisition, Ga4Gap, Ga4Tables, Ga4Tiles, donutRows, gapBars, kpiText, seriesForChart, usdFirst } from './Ga4StoreView'
import type { StoreGap, StoreKpi, StoreSessionRow, StoreTables } from './storeTypes'

/**
 * GA4-STORE-ANALYTICS-001 — what the charts are given, and what the tiles and tables state.
 *
 * Recharts draws nothing in jsdom, so a chart is held by the DATA handed to it (the exported
 * builders) and by the wrapper that says how many marks it was given.
 */
const kpi = (key: string, kind: StoreKpi['kind'], current: number | null, previous: number | null, extra: Partial<StoreKpi> = {}): StoreKpi => ({
  key, kind, current, previous, change_pct: current !== null && previous ? (current - previous) / Math.abs(previous) : null, currency: kind === 'money' ? 'SAR' : null, ...extra,
})

const sessionRows = (n: number): StoreSessionRow[] => Array.from({ length: n }, (_, i) => ({
  name: `source-${String(i + 1).padStart(2, '0')}`, sub: i % 2 ? 'cpc' : 'organic', sessions: (n - i) * 10, engagement_rate: 0.5, purchases: n - i, conversion_rate: 0.1, revenue: (n - i) * 100, revenue_usd: (n - i) * 25,
}))

describe('the GA4 store view', () => {
  it('formats each kind of figure, and puts USD first with the original beside it', () => {
    expect(kpiText({ kind: 'rate', currency: null }, 0.3)).toBe('30.0%')
    expect(kpiText({ kind: 'seconds', currency: null }, 75)).toBe('1:15')
    expect(kpiText({ kind: 'count', currency: null }, 1234)).toBe('1,234')
    expect(kpiText({ kind: 'money', currency: 'SAR' }, 2000, 500)).toBe('500 USD')
    expect(kpiText({ kind: 'money', currency: 'SAR' }, 2000, null)).toBe('2K SAR')
    expect(kpiText({ kind: 'money', currency: 'SAR' }, null, null)).toBe('—')

    expect(usdFirst(500, 2000, 'SAR')).toEqual({ text: '500 USD', original: '2K SAR' })
    // No rate: the original stands alone, never a guessed conversion.
    expect(usdFirst(null, 2000, 'SAR')).toEqual({ text: '2K SAR', original: null })
    expect(usdFirst(null, null, 'SAR')).toEqual({ text: '—', original: null })
  })

  it('draws a tile per KPI with its change, and a withheld figure as a dash with no arrow', () => {
    renderWithProviders(<Ga4Tiles ar={false} kpis={[
      kpi('sessions', 'count', 400, 200, { trend: [{ date: '2026-10-02', value: 100 }, { date: '2026-10-03', value: 300 }] }),
      kpi('bounce_rate', 'rate', 0.6, 0.3),
      kpi('revenue', 'money', 2000, 400, { current_usd: 500, previous_usd: 100 }),
      kpi('aov', 'money', null, null, { current_usd: null }),
    ]} />, { locale: 'en' })

    expect(screen.getByTestId('ga4-tile-sessions')).toHaveTextContent('Sessions')
    expect(screen.getByTestId('ga4-tile-sessions')).toHaveTextContent('400')
    expect(screen.getByTestId('ga4-delta-sessions')).toHaveTextContent('100.0%')
    expect(screen.getByTestId('ga4-delta-sessions')).toHaveAttribute('data-good', 'yes')
    // A bounce rate that doubled went UP and that is the bad direction.
    expect(screen.getByTestId('ga4-delta-bounce_rate')).toHaveAttribute('data-direction', 'up')
    expect(screen.getByTestId('ga4-delta-bounce_rate')).toHaveAttribute('data-good', 'no')
    expect(screen.getByTestId('ga4-tile-revenue')).toHaveTextContent('500 USD')
    expect(screen.getByTestId('ga4-tile-revenue')).toHaveTextContent('2K SAR')
    expect(screen.getByTestId('ga4-tile-aov')).toHaveTextContent('—')
    expect(screen.queryByTestId('ga4-delta-aov')).toBeNull()
  })

  it('hands the line chart USD only when every day converted', () => {
    const days = [
      { date: '2026-10-02', sessions: 100, purchases: 2, revenue: 300, revenue_usd: 75 },
      { date: '2026-10-03', sessions: 300, purchases: 8, revenue: 1700, revenue_usd: 425 },
    ]
    expect(seriesForChart(days, 'SAR')).toEqual({ currency: 'USD', data: [{ date: '2026-10-02', sessions: 100, purchases: 2, revenue: 75 }, { date: '2026-10-03', sessions: 300, purchases: 8, revenue: 425 }] })

    const partial = seriesForChart([days[0], { ...days[1], revenue_usd: null }], 'SAR')
    expect(partial.currency).toBe('SAR')
    expect(partial.data.map((d) => d.revenue)).toEqual([300, 1700])
  })

  it('hands the donuts sessions per slice under a translated name, and draws a chip when a breakdown is absent', () => {
    const slices = [
      { key: 'Paid Social', sessions: 600, purchases: 12, revenue: 2400, revenue_usd: 600, share: 0.6 },
      { key: 'Direct', sessions: 400, purchases: 8, revenue: 1600, revenue_usd: 400, share: 0.4 },
      { key: 'Unassigned', sessions: 0, purchases: 0, revenue: 0, revenue_usd: 0, share: 0 },
    ]
    expect(donutRows(slices, { 'Paid Social': { ar: 'اجتماعي مدفوع', en: 'Paid social' }, Direct: { ar: 'مباشر', en: 'Direct' } }, true)).toEqual([
      { key: 'Paid Social', name: 'اجتماعي مدفوع', value: 600 },
      { key: 'Direct', name: 'مباشر', value: 400 },
    ])

    renderWithProviders(<Ga4Acquisition ar={false} channels={slices} devices={null} funnel={[{ stage: 'sessions', count: 1000, step_rate: null, exceeds_previous: false }]} />, { locale: 'en' })
    expect(screen.getByTestId('ga4-channels')).toHaveAttribute('data-slices', '2')
    expect(screen.getByTestId('ga4-devices-absent')).toHaveTextContent('Unavailable')
    expect(screen.getByTestId('ga4-funnel')).toBeInTheDocument()
  })

  it('sets the platform beside GA4 as two bars and a difference chip, and never a sum', () => {
    const gap: StoreGap = {
      summed: false,
      rows: [
        { platform: 'meta', reported: { conversions: 20, revenue: 1000, currency: 'USD', revenue_usd: 1000 }, ga4: { sessions: 400, purchases: 8, revenue: 1600, currency: 'SAR', revenue_usd: 400, session_share: 0.4 }, gap: { conversions: 12, conversions_share: 0.6, revenue_usd: 600, revenue_share: 0.6 } },
        { platform: 'snapchat', reported: { conversions: 10, revenue: 500, currency: 'USD', revenue_usd: 500 }, ga4: { sessions: 200, purchases: 12, revenue: 2400, currency: 'SAR', revenue_usd: 600, session_share: 0.2 }, gap: { conversions: -2, conversions_share: -0.2, revenue_usd: -100, revenue_share: -0.2 } },
        { platform: 'tiktok', reported: null, ga4: null, gap: { conversions: null, conversions_share: null, revenue_usd: null, revenue_share: null } },
      ],
      unpaid: [{ class: 'organic', sessions: 250, purchases: 7, revenue: 1400, currency: 'SAR', revenue_usd: 350, session_share: 0.25 }],
      other_paid: null,
      ga4_sessions: 1000,
    }
    expect(gapBars(gap, false)).toEqual([
      { label: 'Meta', platform: 'meta', reported: 20, ga4: 8 },
      { label: 'Snapchat', platform: 'snapchat', reported: 10, ga4: 12 },
      { label: 'TikTok', platform: 'tiktok', reported: null, ga4: null },
    ])

    renderWithProviders(<Ga4Gap gap={gap} ar={false} />, { locale: 'en' })
    expect(screen.getByTestId('ga4-gap-not-summed')).toHaveTextContent('Not summed')
    expect(screen.getByTestId('ga4-gap-chip-meta')).toHaveTextContent('+12 · 60%')
    expect(screen.getByTestId('ga4-gap-chip-snapchat')).toHaveTextContent('−2 · 20%')
    expect(screen.getByTestId('ga4-gap-chip-tiktok')).toHaveTextContent('—')
    expect(screen.getByTestId('outside-organic')).toHaveTextContent('Free 250 · 25%')
    // 28 = 20 + 8 and 22 = 10 + 12: no figure on the card is a platform number plus a GA4 number.
    expect(screen.getByTestId('ga4-gap').textContent).not.toMatch(/\b(28|22)\b/)
  })

  it('keeps the tables card the same size across pages and across tabs, and offers no tab for a table the server did not send', () => {
    const tables: StoreTables = {
      sources: { rows: sessionRows(19), total: 19 },
      landing_pages: { rows: sessionRows(3).map((r) => ({ ...r, name: `/page-${r.name}`, sub: null })), total: 3 },
      products: { rows: [{ name: 'عباية سوداء', views: 400, carts: 90, purchased: 14, revenue: 2800, revenue_usd: 700 }], total: 1 },
    }
    renderWithProviders(<Ga4Tables tables={tables} currency="SAR" ar={false} />, { locale: 'en' })

    expect(screen.queryByTestId('ga4-table-tab-campaigns')).toBeNull()
    expect(screen.queryByTestId('ga4-table-tab-countries')).toBeNull()
    const box = () => screen.getByTestId('ga4-table-box')
    const size = () => ({ height: box().style.height, rows: box().querySelectorAll('tbody tr').length })
    const first = size()
    expect(first.rows).toBe(8)
    expect(within(box()).getByText('source-01')).toBeInTheDocument()
    expect(within(box()).getAllByText('475 USD').length).toBeGreaterThan(0)

    fireEvent.click(screen.getByTestId('ga4-table-next'))
    fireEvent.click(screen.getByTestId('ga4-table-next'))
    expect(screen.getByTestId('ga4-table-page')).toHaveTextContent('3 / 3')
    expect(size()).toEqual(first)

    fireEvent.click(screen.getByTestId('ga4-table-tab-landing_pages'))
    expect(screen.getByTestId('ga4-table-page')).toHaveTextContent('1 / 1')
    expect(size()).toEqual(first)

    fireEvent.click(screen.getByTestId('ga4-table-tab-products'))
    expect(within(box()).getByText('عباية سوداء')).toBeInTheDocument()
    expect(within(box()).getByText('700 USD')).toHaveAttribute('dir', 'ltr')
    expect(size()).toEqual(first)
  })

  it('says the tables are unavailable with a chip, not an empty grid', () => {
    renderWithProviders(<Ga4Tables tables={{}} currency="SAR" ar />, { locale: 'ar' })
    expect(screen.getByTestId('ga4-tables-absent')).toHaveTextContent('غير متاح')
  })
})

describe('a breakdown that runs over the visits (REPORT-WEBSITE-TOTALS-001)', () => {
  const devices = [
    { key: 'mobile', sessions: 30000, purchases: null, revenue: null, revenue_usd: null, share: 0.8245 },
    { key: 'desktop', sessions: 6000, purchases: null, revenue: null, revenue_usd: null, share: 0.1649 },
    { key: 'tablet', sessions: 384, purchases: null, revenue: null, revenue_usd: null, share: 0.0106 },
  ]
  const meta = { total: 36361, measured: 36384, classified: 36361, unclassified: 0, over: 23, days_covered: 30, days_total: 30 }

  it('keeps every measured slice, centres the ring on the measured sum labelled as such, and names the difference', () => {
    expect(donutRows(devices, {}, false).map((r) => r.value)).toEqual([30000, 6000, 384])

    renderWithProviders(<Ga4Acquisition ar={false} channels={null} devices={devices} devicesMeta={meta} funnel={[]} />, { locale: 'en' })
    const ring = screen.getByTestId('ga4-devices')
    expect(ring).toHaveTextContent('Breakdown sum')
    expect(ring).toHaveTextContent('36,384')
    expect(ring).not.toHaveTextContent('36,361')
    expect(screen.getByTestId('ga4-devices-over')).toHaveTextContent('+23 over visits')
    expect(screen.getByTestId('ga4-devices-coverage')).not.toHaveTextContent('Unclassified')
  })

  it('says it in Arabic too', () => {
    renderWithProviders(<Ga4Acquisition ar channels={null} devices={devices} devicesMeta={meta} funnel={[]} />)
    expect(screen.getByTestId('ga4-devices-over')).toHaveTextContent('عن الزيارات (تقارير Google تُحدَّث في أوقات مختلفة)')
    expect(screen.getByTestId('ga4-devices')).toHaveTextContent('مجموع التقسيم')
  })
})
