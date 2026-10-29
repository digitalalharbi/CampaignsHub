import { afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { LiveSharedReport } from '../LiveSharedReport'

vi.mock('../api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../api')>()),
  fetchLiveShared: vi.fn(),
  fetchLiveContent: vi.fn(),
}))

import { fetchLiveShared } from '../api'

/** NASEL 2026-10-29 — every report setting reaches the page: sections, the KPI row, «no Results» → clicks, the Results label, revenue. */
const ev = { lead_form: 'form_leads', landing_page: 'purchases' }
const base = {
  period: { from: '2026-10-01', to: '2026-10-29', days: 29 },
  currency: 'USD',
  detail_level: 'detailed',
  totals: { spend: 698, impressions: 138_685, clicks: 6288, ctr: 0.0453, cpc: 0.111, conversions: 1129, cpa: 0.62, revenue: 0, roas: 0, leads: 2, form_leads: 100, purchases: 2, checkout: 147, landing_page_views: 45 },
  deltas: {},
  timeseries: [{ date: '2026-10-28', spend: 300, clicks: 3000, impressions: 60_000, form_leads: 40, conversions: 500 }],
  platforms: [
    { provider: 'tiktok', spend: 258, impressions: 80_302, clicks: 5525, conversions: 1126, cpa: 0.23, cpc: 0.047, ctr: 0.0688, form_leads: 13 },
    { provider: 'meta', spend: 197, impressions: 19_490, clicks: 400, conversions: 2, cpa: 98.48, cpc: 0.49, ctr: 0.0205, form_leads: 39 },
    { provider: 'snapchat', spend: 243, impressions: 38_893, clicks: 363, conversions: 1, cpa: 243.32, cpc: 0.67, ctr: 0.0093, form_leads: 48 },
  ],
  accounts: [{ account_id: 'a1', provider: 'tiktok', account_name: 'Nasl', spend: 258, impressions: 80_302, clicks: 5525, conversions: 1126, cpa: 0.23 }],
  campaigns: [], funnel: [], ads: [], ads_weakest: [], ads_groups: [], ads_roster: [], sections: { attribution: false }, store_funnel: null, freshness: [],
  available: { providers: ['tiktok', 'meta', 'snapchat'], campaigns: [], earliest: '2026-10-01', latest: '2026-10-29' },
  applied: { from: '2026-10-01', to: '2026-10-29', providers: [], campaigns: [] },
  results: {
    configured: true, total: 102, cost: 6.84, by_path: {}, events: { meta: ev, tiktok: ev, snapchat: ev },
    by_platform: { tiktok: { results: 13, metric: 'form_leads', spend: 258, cost: 19.8 }, meta: { results: 40, metric: 'purchases', spend: 197, cost: 4.9 }, snapchat: { results: 49, metric: 'form_leads', spend: 243, cost: 4.96 } },
    leads: {
      configured: true, total: 102, native: 100, landing: 2, cost: 6.84, by_platform: {}, by_path: {}, events: { meta: ev, tiktok: ev, snapchat: ev },
      native_by_platform: { tiktok: 13, meta: 39, snapchat: 48 }, landing_by_platform: { meta: 1, snapchat: 1 },
      landing_funnel: { campaigns: 3, page_views: 45, form_starts: 127, contacts: null, completions: 2, completion_rate: 0.0157, spend: 300, cost_per_completion: 150, coverage: { page_views: ['snapchat'], form_starts: ['meta', 'tiktok'], completions: ['meta', 'snapchat'] }, basis: '' },
    },
  },
}
const serve = (over: Record<string, unknown>) => vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: { ...base, ...over } } } as never)
const open = (view: string, form = 'detailed') => renderWithProviders(<LiveSharedReport token="tok" currency="USD" form={form as 'detailed'} />, { route: `/r/tok?view=${view}`, locale: 'ar' })
const ALL = ['kpis', 'trends', 'platform_comparison', 'content_performance', 'daily_records', 'detailed_tables', 'account_breakdown', 'funnel']

describe('NASEL report controls reach the page', () => {
  afterEach(() => vi.clearAllMocks())

  it('«مسار التحويل» off hides the lead strip; on shows it with a coverage warning when steps come from different platforms', async () => {
    serve({ metrics: ['spend', 'impressions', 'clicks', 'native_leads'], report_sections: ALL.filter((k) => k !== 'funnel') })
    const first = open('summary', 'executive_summary')
    await screen.findByTestId('exec-platform-cards')
    expect(screen.queryByTestId('exec-lead-summary')).not.toBeInTheDocument()
    first.unmount()

    serve({ metrics: ['spend', 'impressions', 'clicks', 'native_leads'], report_sections: ALL })
    open('summary', 'executive_summary')
    expect(await screen.findByTestId('exec-lead-summary')).toBeInTheDocument()
    expect(screen.getByTestId('exec-lead-coverage')).toHaveTextContent('تختلف تغطية الخطوات')
  })

  it('«الجداول التفصيلية» off hides the platform table in the Tables view', async () => {
    serve({ metrics: ['spend', 'clicks'], report_views: ['summary', 'tables'], report_sections: ALL.filter((k) => k !== 'detailed_tables') })
    open('tables')
    const tables = await screen.findByTestId('live-mode-tables')
    expect(within(tables).queryByTestId('tables-platforms')).not.toBeInTheDocument()
  })

  it('the KPI row is exactly the chosen metrics, in order, under their custom names — Results and their cost included', async () => {
    serve({ metrics: ['spend', 'results', 'cost_per_result', 'clicks'], kpi_labels: { results: 'إجمالي الليدز', cost_per_result: 'تكلفة الليد' }, report_sections: ALL })
    open('summary', 'executive_summary')
    const row = await screen.findByTestId('live-kpis')
    const text = row.textContent ?? ''
    expect(text).toContain('إجمالي الليدز')
    expect(text).toContain('تكلفة الليد')
    expect(text.indexOf('الإنفاق')).toBeLessThan(text.indexOf('إجمالي الليدز'))
    expect(text.indexOf('إجمالي الليدز')).toBeLessThan(text.indexOf('تكلفة الليد'))
    expect(text).toContain('102')
  })

  it('Results «none»: no «النتائج» or «نتيجة» anywhere — clicks stand in, matching the platforms', async () => {
    serve({ results_off: true, results: { ...base.results, configured: false, total: null, cost: null, by_platform: {} }, metrics: ['spend', 'impressions', 'clicks', 'conversions', 'cpa'], report_views: ['summary', 'platforms', 'tables'], report_sections: ALL })
    open('tables')
    const tables = await screen.findByTestId('live-mode-tables')
    expect(tables.textContent).not.toMatch(/النتائج|نتيجة/)
    expect(within(tables).getByTestId('tables-platforms')).toHaveTextContent('تكلفة النقرة')

    fireEvent.click(screen.getByTestId('live-mode-tab-summary'))
    const summary = await screen.findByTestId('live-mode-summary')
    expect(summary.textContent).not.toMatch(/النتائج|نتيجة/)
    expect(within(summary).getByTestId('live-kpis')).toHaveTextContent('6.29K')
  })

  it('the Platforms tab cards state each platform\'s clicks, never its generic conversions', async () => {
    serve({ metrics: ['spend', 'clicks'], report_views: ['summary', 'platforms'], report_sections: ALL })
    open('platforms')
    const card = await screen.findByTestId('live-platform-card-tiktok')
    expect(card).toHaveTextContent('5,525')
    expect(card).toHaveTextContent('نقرة')
    expect(card).not.toHaveTextContent('1,126')
    expect(screen.getByTestId('live-platform-card-meta')).toHaveTextContent('400')
    expect(screen.getByTestId('live-platform-card-snapchat')).toHaveTextContent('363')
  })

  it('a Results label renames Results and their cost across the tables', async () => {
    serve({ results_label: 'الاهتمام', metrics: ['spend', 'clicks'], report_views: ['summary', 'tables'], report_sections: ALL })
    open('tables')
    const platforms = await screen.findByTestId('tables-platforms')
    expect(platforms).toHaveTextContent('الاهتمام')
    expect(platforms).toHaveTextContent('تكلفة الاهتمام')
    expect(platforms).not.toHaveTextContent('النتائج')
  })

  it('zero revenue everywhere hides revenue and ROAS, even when chosen', async () => {
    serve({ metrics: ['spend', 'revenue', 'roas', 'clicks'], report_sections: ALL })
    open('summary', 'executive_summary')
    const row = await screen.findByTestId('live-kpis')
    expect(row.textContent).not.toMatch(/الإيرادات|العائد على الإنفاق/)
  })
})
