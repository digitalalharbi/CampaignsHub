import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { Ga4ReportBlocks, Ga4Slide, ga4PartShown, type WithGa4Sections } from './Ga4ReportBlocks'
import { GA4_PRINT_ROWS_MAX, GA4_ROWS_PER_PAGE, withGa4Slides } from './reportSections'

/** GA4-REPORT-SECTIONS-001 — the live link and the saved report draw the parts the server resolved, and nothing else. */
interface Page { id: string; type: string; order: number; visible: boolean; range?: [number, number] }

const row = (i: number) => ({ name: `src-${String(i).padStart(2, '0')}`, sub: 'cpc', sessions: 1000 - i, engagement_rate: 0.5, purchases: 5, conversion_rate: 0.01, revenue: 100, revenue_usd: 25 })
const payload = (sections: string[], over: Partial<WithGa4Sections> = {}): WithGa4Sections => ({
  report_sections: sections,
  ga4_overview: {
    currency: 'SAR', usd: { available: true, from: 'SAR', rate: 0.25 }, period: { from: '2026-10-01', to: '2026-10-31' }, previous_period: { from: '2026-08-31', to: '2026-09-30' },
    property: { name: 'Demo store' }, last_synced_at: null,
    kpis: [{ key: 'sessions', kind: 'count', current: 1000, previous: 800, change_pct: 0.25, currency: null }], series: [],
  },
  ga4_acquisition: { currency: 'SAR', channels: [{ key: 'Direct', sessions: 400, purchases: 8, revenue: 1600, revenue_usd: 400, share: 1 }], devices: null, funnel: [] },
  ga4_platform_gap: { summed: false, currency: 'SAR', rows: [], unpaid: [{ class: 'direct', sessions: 400, purchases: 8, revenue: 1600, currency: 'SAR', revenue_usd: 400, session_share: 0.4 }], other_paid: null, ga4_sessions: 1000 },
  ga4_tables: { currency: 'SAR', tables: { sources: { rows: Array.from({ length: 30 }, (_, i) => row(i + 1)), total: 30 }, products: { rows: [{ name: 'Tee', views: 1, carts: 1, purchased: 1, revenue: 10, revenue_usd: 2.5 }], total: 1 } } },
  ...over,
})
const ALL = ['kpis', 'ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables']

describe('the GA4 parts of a report', () => {
  it('draws each part only where the server named it and sent it', () => {
    expect(ga4PartShown(payload(ALL), 'ga4_overview')).toBe(true)
    expect(ga4PartShown(payload(['kpis']), 'ga4_overview')).toBe(false)
    expect(ga4PartShown(payload(ALL, { ga4_overview: null }), 'ga4_overview')).toBe(false)
    // A payload from before the parts existed carries no list: it gains nothing.
    expect(ga4PartShown({ ...payload(ALL), report_sections: undefined }, 'ga4_overview')).toBe(false)

    const { unmount } = renderWithProviders(<Ga4ReportBlocks payload={payload(['ga4_overview', 'ga4_tables'])} ar={false} />, { locale: 'en' })
    expect(screen.getByTestId('report-ga4-overview')).toBeInTheDocument()
    expect(screen.getByTestId('report-ga4-tables')).toBeInTheDocument()
    expect(screen.queryByTestId('report-ga4-acquisition')).toBeNull()
    expect(screen.queryByTestId('report-ga4-gap')).toBeNull()
    unmount()

    const none = renderWithProviders(<Ga4ReportBlocks payload={payload(['kpis'])} ar={false} />, { locale: 'en' })
    expect(none.container.querySelector('[data-testid="report-ga4"]')).toBeNull()
  })

  it('draws no campaigns tab on a client link, because the server sent no campaigns table', () => {
    renderWithProviders(<Ga4ReportBlocks payload={payload(ALL)} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('ga4-table-tab-sources')).toBeInTheDocument()
    expect(screen.queryByTestId('ga4-table-tab-campaigns')).toBeNull()
    expect(screen.getByTestId('report-ga4').textContent).not.toMatch(/spend|الإنفاق/i)
  })

  it('gives the saved report one page per part on screen, and splits a table into fixed pages on paper', () => {
    const screenPages = withGa4Slides<Page>([], payload(ALL))
    expect(screenPages.map((s) => s.type)).toEqual(['__ga4_overview', '__ga4_acquisition', '__ga4_gap', '__ga4_tables'])

    const paper = withGa4Slides<Page>([], payload(ALL), true)
    const tablePages = paper.filter((s) => s.type === '__ga4_table')
    // 30 source rows are capped at the print maximum and split, never shrunk; the one product row is its own page.
    expect(tablePages.map((s) => [s.id, s.range])).toEqual([
      ['__ga4_table:sources:0', [0, 8]], ['__ga4_table:sources:8', [8, 16]], ['__ga4_table:sources:16', [16, 24]], ['__ga4_table:products:0', [0, 1]],
    ])
    expect(GA4_PRINT_ROWS_MAX % GA4_ROWS_PER_PAGE).toBe(0)

    // A part the operator hid, or a snapshot from before the parts existed, gains no page.
    expect(withGa4Slides<Page>([], payload(['kpis']), true)).toEqual([])
    expect(withGa4Slides<Page>([], { ...payload(ALL), report_sections: undefined })).toEqual([])
    expect(withGa4Slides<Page>([], payload(ALL, { ga4_tables: null }), true).some((s) => s.type === '__ga4_table')).toBe(false)
  })

  it('prints a table page as exactly its rows, padded to the page, with the range stated', () => {
    const data = payload(ALL)
    renderWithProviders(<Ga4Slide slide={{ id: '__ga4_table:sources:16', type: '__ga4_table', range: [16, 24] }} data={data} ar={false} paged />, { locale: 'en' })

    const page = screen.getByTestId('slide-ga4-table-sources')
    expect(page).toHaveTextContent('17–24 / 30')
    expect(page.querySelectorAll('tbody tr')).toHaveLength(8)
    expect(page.querySelectorAll('tbody tr[data-row="filled"]')).toHaveLength(8)
    expect(page).toHaveTextContent('src-17')
    expect(page).not.toHaveTextContent('src-16')
    expect(screen.queryByTestId('ga4-print-table-pager')).toBeNull()
  })
})
