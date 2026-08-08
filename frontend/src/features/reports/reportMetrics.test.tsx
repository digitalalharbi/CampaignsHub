import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SlideBody, type ReportData, type Slide } from './InteractiveReport'
import { reportMetrics, trendSeries } from './reportMetrics'

/**
 * §14.6 on the client — a report renders the cards its objective calls for, and nothing else.
 *
 * The brand case is the one that matters. Its ROAS and CPA are null for a reason that is not a gap
 * in the data, and the old deck printed both as the two largest cards on the first page a client
 * sees. A dash there is not neutral: it reads as a return that could not be measured, when in fact
 * no return was being bought.
 */

const brand: ReportData = {
  period: { from: '2026-07-01', to: '2026-07-31' },
  currency: 'SAR',
  objective: 'awareness',
  metric_set: ['impressions', 'reach', 'frequency', 'cpm', 'video_views', 'ctr'],
  kpis: { spend: 40000, impressions: 10_000_000, reach: 2_400_000, frequency: 4.16, cpm: 4, video_views: 0, ctr: 0.004, revenue: 0, conversions: 0, roas: null, cpa: null },
  reported: { impressions: true, reach: true, video_views: false, revenue: false, conversions: false, spend: true },
  delta: { impressions: 0.2, reach: 0.1 },
  timeseries: [{ date: '2026-07-01', spend: 1000, impressions: 300_000 }],
  platforms: [{ provider: 'meta', spend: 30000, impressions: 6_000_000, reach: 1_500_000, cpm: 5, ctr: 0.004 }],
  campaigns: [],
  best: { basis: { key: 'cpm', label_ar: 'تكلفة الألف ظهور' }, platform: 'snapchat', platform_value: '2.50 SAR', platform_by_roas: null, platform_by_cpa: null, campaign: 'حملة وعي' },
  slides: [],
}

const sales: ReportData = {
  ...brand,
  objective: 'sales',
  metric_set: ['spend', 'revenue', 'conversions', 'roas', 'cpa', 'ctr'],
  kpis: { spend: 20000, revenue: 80000, conversions: 700, roas: 4, cpa: 28.57, ctr: 0.02, impressions: 900_000 },
  reported: { spend: true, revenue: true, conversions: true, impressions: true },
  best: { basis: { key: 'roas', label_ar: 'ROAS' }, platform: 'meta', platform_value: '6.00×', platform_by_roas: 'meta', platform_by_cpa: 'meta', campaign: 'حملة مبيعات' },
}

const slide: Slide = { id: 'executive_summary', type: 'executive_summary', order: 1, visible: true }
const meta = { reportName: 'تقرير', platforms: ['meta'] }

describe('reportMetrics', () => {
  it('leads a brand report with attention, and offers no return or cost per order', () => {
    const keys = reportMetrics(brand).map((m) => m.key)

    expect(keys).toEqual(['impressions', 'reach', 'frequency', 'cpm', 'video_views', 'ctr'])
    expect(keys).not.toContain('roas')
    expect(keys).not.toContain('cpa')
  })

  it('leads a sales report with the return, unchanged', () => {
    const keys = reportMetrics(sales).map((m) => m.key)

    expect(keys).toContain('roas')
    expect(keys).toContain('revenue')
  })

  /**
   * The rule the whole catalogue exists for. `video_views` is 0 in `kpis` because the pivot
   * coalesces, and `reported` is the only thing that knows no platform ever sent it.
   */
  it('a metric no platform sent is a state, never a zero', () => {
    const views = reportMetrics(brand).find((m) => m.key === 'video_views')!

    expect(brand.kpis.video_views).toBe(0)
    expect(views.reading).toEqual({ kind: 'not_provided' })
  })

  /** REPORT-OBJECTIVE-003, carried forward: the Direct pair takes the card and says so. */
  it('substitutes the direct figures for the blended pair, and drops the blended delta with them', () => {
    const withSplit = {
      ...sales,
      delta: { roas: 0.5, cpa: -0.2 },
      objective_performance: {
        paths: [],
        direct: { label_ar: '', label_en: '', spend: 5000, orders: 250, revenue: 40000, cpa: 20, roas: 8, aov: 160, formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [] },
        blended: { label_ar: '', label_en: '', spend: 20000, orders: 700, revenue: 80000, blended_cpa: 28.57, blended_roas: 4, formula: { blended_cpa: '', blended_roas: '' }, includes_non_sales_spend: 15000 },
      },
    } as ReportData

    const roas = reportMetrics(withSplit).find((m) => m.key === 'roas')!

    expect(roas.label).toBe('ROAS (مبيعات مباشرة)')
    expect(roas.reading).toEqual({ kind: 'value', text: '8.00×' })
    // The movement was computed on the blended figure; attaching it to the direct one would put one
    // scope's trend beside another scope's number.
    expect(roas.delta).toBeUndefined()
  })

  /** An older snapshot has no `metric_set` and must still render something sensible. */
  it('falls back to the catalogue when a snapshot predates the stored set', () => {
    const { metric_set: _dropped, ...older } = brand
    const keys = reportMetrics(older as ReportData).map((m) => m.key)

    expect(keys).toContain('impressions')
    expect(keys).not.toContain('roas')
  })
})

describe('the executive slide', () => {
  it('renders no ROAS or CPA card on a brand report', () => {
    render(<SlideBody slide={slide} data={brand} meta={meta} />)

    expect(screen.getByText('الظهور')).toBeInTheDocument()
    expect(screen.queryByText('ROAS')).not.toBeInTheDocument()
    expect(screen.queryByText('CPA')).not.toBeInTheDocument()
  })

  /** The leader board says what it ranked on — «أفضل منصة (ROAS)» was printed on every report. */
  it('names the metric its leader board ranked on', () => {
    render(<SlideBody slide={slide} data={brand} meta={meta} />)

    expect(screen.getByText('أفضل منصة (تكلفة الألف ظهور)')).toBeInTheDocument()
    expect(screen.getByText('snapchat')).toBeInTheDocument()
    expect(screen.getByText('2.50 SAR')).toBeInTheDocument()
  })

  it('still leads a sales report with the return', () => {
    render(<SlideBody slide={slide} data={sales} meta={meta} />)

    expect(screen.getByText('ROAS')).toBeInTheDocument()
    expect(screen.getByText('أفضل منصة (ROAS)')).toBeInTheDocument()
  })

  /**
   * The selectable strip under the cards is what a PDF reader extracts, so a zero printed there is
   * the most durable form of the claim.
   */
  it('never writes an unreported metric into the exact-figures strip', () => {
    const { container } = render(<SlideBody slide={slide} data={brand} meta={meta} />)
    const exact = container.querySelector('[data-exact]')!

    expect(exact.textContent).toContain('الإنفاق')
    expect(exact.textContent).not.toContain('مشاهدات الفيديو')
    expect(exact.textContent).not.toContain('الإيرادات')
  })
})

/**
 * The daily trend, which used to be «spend vs revenue» on every report — so a brand month drew a
 * revenue line that was zero on all thirty of its days: a flat line along the axis, which reads as
 * a campaign that earned nothing rather than one that was not selling.
 */
describe('the daily trend', () => {
  it('plots spend against what this money was buying', () => {
    expect(trendSeries('awareness').key).toBe('impressions')
    expect(trendSeries('traffic').key).toBe('clicks')
    expect(trendSeries('leads').key).toBe('conversions')
    expect(trendSeries('video').key).toBe('video_views')
  })

  it('keeps revenue for a sales report, and plots it nowhere else', () => {
    expect(trendSeries('sales').key).toBe('revenue')
    expect(trendSeries('custom').key).not.toBe('revenue')
    expect(trendSeries(undefined).key).not.toBe('revenue')
  })

  it('titles the brand report’s chart after what it actually draws', () => {
    render(<SlideBody slide={slide} data={brand} meta={meta} />)

    expect(screen.getByText('الإنفاق مقابل الظهور')).toBeInTheDocument()
    expect(screen.queryByText('الإنفاق مقابل الإيرادات')).not.toBeInTheDocument()
  })

  /** A count of platforms under a trophy is not a highlight, and reads as one. */
  it('shows no filler card where there is no cost-per ranking', () => {
    render(<SlideBody slide={slide} data={brand} meta={meta} />)

    expect(screen.queryByText('عدد المنصات')).not.toBeInTheDocument()
    expect(screen.queryByText('أقل تكلفة نتيجة')).not.toBeInTheDocument()
  })
})
