import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { barMetrics, ContentTypesComparison, rankedFormats } from './ContentTypesComparison'
import type { FormatComparisonObjective, FormatComparisonPayload, FormatComparisonRow } from './api'
import { withFormatComparisonSlides } from '@/features/reports/reportSections'
import { FormatComparisonReportBlock, FormatComparisonSlide } from '@/features/reports/FormatComparisonBlocks'

/**
 * CONTENT-FORMAT-COMPARISON-001 — the comparison draws what the server decided, with switches a reader can use by
 * keyboard, a drill into a format's creatives, and no money on a client's copy.
 */
const row = (format: string, over: Partial<FormatComparisonRow> = {}): FormatComparisonRow => ({
  format, tokens: [], creatives: 2, creatives_with_metrics: 2, spend: 100, spend_withheld: false, spend_original: null, original_currency: null,
  impressions: 10000, clicks: 100, results: 10, results_partial: false, cost_per_result: 10, ctr: 0.01, cpm: 10, cpc: 1, conversion_rate: 0.1,
  roas: null, revenue: null, share_of_spend: 0.5, share_of_results: 0.5, small_sample: false, small_sample_reason: null,
  creative_list: [{ id: `${format}-1`, name: `${format} ad one`, token: format, spend: 60, results: 6, cost_per_result: 10, ctr: 0.01, impressions: 6000, clicks: 60, results_not_attributable: false }],
  creative_list_total: 1, previous: { cost_per_result: 20, results: 5 }, ...over,
})

const sales = (formats: FormatComparisonRow[], over: Partial<FormatComparisonObjective> = {}): FormatComparisonObjective => ({
  family: 'sales', label: { ar: 'المبيعات', en: 'Sales' }, result_metric: 'conversions', cost_metric: 'cpa', lower_is_better: true,
  totals: { creatives: 6, spend: 300, results: 35, cost_per_result: 8.57 }, coverage: { spend_known_share: 0.9, results_known_share: 0.8 },
  reconciles: true, formats, winner: { format: 'video', metric: 'cpa', value: 4 }, winner_refusal: null,
  trend: { metric: 'cpa', result: 'conversions', points: [{ from: '2026-09-01', to: '2026-09-04', formats: { video: { results: 5, cost_per_result: 4 }, image: { results: 2, cost_per_result: 9 } } }] },
  ...over,
})

const payload = (objectives: FormatComparisonObjective[], over: Partial<FormatComparisonPayload> = {}): FormatComparisonPayload => ({
  period: { from: '2026-09-01', to: '2026-09-30' }, previous_period: { from: '2026-08-02', to: '2026-08-31' }, currency: 'SAR',
  totals: { creatives: 6, creatives_with_metrics: 6, spend: 300, reconciles: true }, coverage: { creatives_total: 6, creatives_known: 5, spend_known_share: 0.9 },
  objectives, sample_rule: { minimum_creatives: 2, material_share: 0.01 }, ...over,
})

const formats = [
  row('image', { cost_per_result: 9, share_of_spend: 0.6, share_of_results: 0.3, ctr: 0.02 }),
  row('video', { cost_per_result: 4, share_of_spend: 0.3, share_of_results: 0.6, ctr: 0.005 }),
  row('carousel', { creatives: 1, small_sample: true, small_sample_reason: 'fewer_creatives_than_minimum', cost_per_result: 1, share_of_spend: 0.05, share_of_results: 0.05 }),
  row('unclassified', { tokens: [{ token: 'story_ad', creatives: 1 }], cost_per_result: 12, share_of_spend: 0.05, share_of_results: 0.05 }),
]

describe('the content type comparison', () => {
  it('switches the donut between spend and results, and the bars between metrics', () => {
    renderWithProviders(<ContentTypesComparison data={payload([sales(formats)])} ar={false} />, { locale: 'en' })

    const donut = screen.getByTestId('format-donut')
    expect(donut).toHaveAttribute('data-basis', 'spend')
    expect(donut.getAttribute('data-slices')).toContain('image:0.6000')
    fireEvent.click(screen.getByTestId('format-share-results'))
    expect(screen.getByTestId('format-donut')).toHaveAttribute('data-basis', 'results')
    expect(screen.getByTestId('format-donut').getAttribute('data-slices')).toContain('video:0.6000')
    expect(screen.getByTestId('format-share-results')).toHaveAttribute('aria-checked', 'true')

    expect(screen.getByTestId('format-bars')).toHaveAttribute('data-metric', 'cost_per_result')
    fireEvent.click(screen.getByTestId('format-metric-ctr'))
    expect(screen.getByTestId('format-bars')).toHaveAttribute('data-metric', 'ctr')
    expect(screen.getByTestId('format-bars').getAttribute('data-values')).toContain('Image:0.02')
    // ROAS is not offered where no format reported a return.
    expect(screen.queryByTestId('format-metric-roas')).toBeNull()
  })

  it('names the kinds through the one vocabulary, marks the small sample, and states the server’s verdict', () => {
    renderWithProviders(<ContentTypesComparison data={payload([sales(formats)])} ar />, { locale: 'ar' })

    expect(screen.getByTestId('format-winner')).toHaveTextContent('فيديو · الأقل تكلفة للنتيجة')
    const carousel = screen.getByTestId('format-row-carousel')
    expect(within(carousel).getByTestId('format-small-sample')).toHaveTextContent('عينة صغيرة')
    expect(within(screen.getByTestId('format-row-unclassified')).getByText('غير مصنّف')).toBeInTheDocument()
    expect(screen.getByTestId('format-tokens')).toHaveTextContent('story_ad')
    expect(screen.getByTestId('format-coverage')).toHaveTextContent('90%')
    // The unclassified slice ranks last whatever its cost; a format without a figure is never ranked as a zero.
    // The server's verdict leads the ranking: a small sample (carousel, cheaper on two ads) never sits above the format
    // the card calls «الأقل تكلفة», and a client — who has no cost to sort on — sees «الأنسب» first, not second.
    expect(rankedFormats(sales(formats)).map((f) => f.format)).toEqual(['video', 'carousel', 'image', 'unclassified'])
    const clientCopy = sales(formats.map((f) => ({ ...f, cost_per_result: null, results: f.format === 'image' ? 30 : 10 })))
    expect(rankedFormats(clientCopy)[0].format).toBe('video')
  })

  it('leads with a big card per format — result and cost of a result — with sub-cards, then charts, then a short table', () => {
    renderWithProviders(<ContentTypesComparison data={payload([sales(formats)])} ar />, { locale: 'ar' })

    const order = Array.from(screen.getByTestId('format-objective-sales').querySelectorAll('[data-testid]')).map((e) => e.getAttribute('data-testid'))
    expect(order.indexOf('format-cards')).toBeLessThan(order.indexOf('format-donut'))
    expect(order.indexOf('format-donut')).toBeLessThan(order.indexOf('format-top-creatives'))
    const video = screen.getByTestId('format-card-video')
    expect(within(video).getByText('فيديو')).toBeInTheDocument()
    expect(screen.getByTestId('format-card-results-video')).toHaveTextContent('10')
    expect(screen.getByTestId('format-card-cost-video')).toHaveTextContent('4')
    expect(within(video).getByText('CPM')).toBeInTheDocument()
    expect(within(screen.getByTestId('format-card-shares-video')).getByText('60.0%')).toBeInTheDocument()
    // The only table on screen is the short top-content list: five rows a page.
    expect(screen.queryByTestId('format-table')).toBeNull()
    expect(screen.getByTestId('format-top-creatives')).toBeInTheDocument()
  })

  it('never lets a percentage trail a cost label in the page text (the Analytics unit guard)', () => {
    // The e2e guard flattens the page to text and refuses «CPM|CPA|ROAS|تكلفة النتيجة … 12%» within 24 characters.
    const MONEY_AS_PERCENT = /\b(CPC|CPM|CPA|ROAS|تكلفة النقرة|تكلفة الألف ظهور|تكلفة النتيجة|العائد على الإنفاق)\b[^%\n]{0,24}\d[\d,.]*\s*%/
    for (const ar of [true, false]) {
      const { container, unmount } = renderWithProviders(<ContentTypesComparison data={payload([sales(formats)])} ar={ar} />, { locale: ar ? 'ar' : 'en' })
      const text = (container.textContent ?? '')
      const flat = Array.from(container.querySelectorAll('article')).map((a) => (a as HTMLElement).innerText ?? a.textContent ?? '').join(' ').replace(/\s+/g, ' ')
      expect(flat).not.toMatch(MONEY_AS_PERCENT)
      expect(text).toContain('SAR')
      unmount()
    }
  })

  it('opens a format’s creatives and closes them again, by keyboard-reachable buttons', () => {
    renderWithProviders(<ContentTypesComparison data={payload([sales(formats)])} ar={false} />, { locale: 'en' })

    const open = screen.getByTestId('format-drill-video')
    expect(open.tagName).toBe('BUTTON')
    expect(open).toHaveAttribute('aria-expanded', 'false')
    fireEvent.click(open)
    expect(screen.getByTestId('format-drill-video')).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByTestId('format-drill')).toHaveAttribute('data-format', 'video')
    expect(within(screen.getByTestId('format-drill')).getByText('video ad one')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('format-drill-close'))
    expect(screen.queryByTestId('format-drill')).toBeNull()
  })

  it('gives a client results and shares — no spend, no cost, no money metric', () => {
    const client = payload([sales(formats.map((f) => ({ ...f, spend: null, cost_per_result: null, cpm: null, cpc: null, share_of_spend: null })), { winner: { format: 'video', metric: 'cpa', value: null } })], { currency: null, spend_hidden: true })
    renderWithProviders(<ContentTypesComparison data={client} ar />, { locale: 'ar' })

    expect(screen.queryByTestId('format-share')).toBeNull()
    expect(screen.getByTestId('format-donut')).toHaveAttribute('data-basis', 'results')
    expect(screen.queryByTestId('format-metric-cost_per_result')).toBeNull()
    expect(screen.queryByTestId('format-metric-cpm')).toBeNull()
    expect(screen.getByTestId('format-winner')).toHaveTextContent('الأنسب للهدف')
    expect(within(screen.getByTestId('format-top-creatives')).queryByText('التكلفة')).toBeNull()
    expect(screen.queryByTestId('format-card-cost-video')).toBeNull()
    expect(within(screen.getByTestId('format-card-shares-video')).queryByText('الإنفاق')).toBeNull()
    expect(barMetrics(client.objectives[0], true)).toEqual(['results', 'ctr', 'conversion_rate'])
  })

  it('never compares results across objectives: one tab per objective', () => {
    const traffic = sales([row('image', { cost_per_result: 0.5 })], { family: 'traffic', label: { ar: 'الزيارات', en: 'Traffic' }, result_metric: 'clicks', cost_metric: 'cpc', winner: null, winner_refusal: 'fewer_than_two_comparable_formats' })
    renderWithProviders(<ContentTypesComparison data={payload([sales(formats), traffic])} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('format-objective-sales')).toBeInTheDocument()
    fireEvent.click(screen.getByTestId('format-objective-tab-traffic'))
    expect(screen.getByTestId('format-objective-traffic')).toBeInTheDocument()
    expect(screen.queryByTestId('format-objective-sales')).toBeNull()
    expect(screen.getByTestId('format-no-winner')).toHaveTextContent('Not enough to compare')
  })
})

describe('the report part (REPORT-FORMAT-COMPARE-COMPACT-001)', () => {
  interface Page { id: string; type: string; order: number; visible: boolean; range?: [number, number] }
  const many = Array.from({ length: 8 }, (_, i) => row(['image', 'video', 'carousel', 'collection', 'catalog', 'unclassified', 'image', 'video'][i]))
  const traffic = (formats: FormatComparisonRow[]) => sales(formats, { family: 'traffic', label: { ar: 'الزيارات', en: 'Traffic' }, result_metric: 'clicks', cost_metric: 'cpc', winner: null, winner_refusal: 'fewer_than_two_comparable_formats' })

  it('adds ONE page per report — on screen and on paper, whatever the objectives and rows — only where the server named the part', () => {
    const data = { report_sections: ['kpis', 'content_format_comparison'], content_format_comparison: payload([sales(many), traffic(many)]) }

    expect(withFormatComparisonSlides<Page>([], { ...data, report_sections: ['kpis'] })).toEqual([])
    expect(withFormatComparisonSlides<Page>([], { ...data, report_sections: undefined })).toEqual([])
    expect(withFormatComparisonSlides<Page>([], data).map((s) => s.type)).toEqual(['__formats'])
    expect(withFormatComparisonSlides<Page>([], data, true).map((s) => s.type)).toEqual(['__formats'])
    expect(withFormatComparisonSlides<Page>([], { ...data, content_format_comparison: payload([sales([])]) })).toEqual([])
  })

  it('draws small parallel cards with only the figures that exist: no zero, no «—», no money for a client', () => {
    const zeroCarousel = row('carousel', { results: 0, conversion_rate: 0, share_of_results: 0, ctr: 0.01, roas: null })
    const empty = row('collection', { results: null, cost_per_result: null, ctr: null, conversion_rate: null, roas: null })
    const data = { audience: 'client', report_sections: ['content_format_comparison'], content_format_comparison: payload([sales([row('video'), row('image'), zeroCarousel, empty])], { spend_hidden: true }) }
    renderWithProviders(<FormatComparisonSlide data={data} />, { locale: 'ar' })

    const strip = screen.getByTestId('report-format-strip')
    expect(within(strip).getByTestId('format-strip-card-video')).toBeInTheDocument()
    expect(within(strip).queryByTestId('format-strip-card-collection')).toBeNull()
    const carousel = within(strip).getByTestId('format-strip-card-carousel')
    expect(within(carousel).queryByTestId('format-strip-cell-results')).toBeNull()
    expect(within(carousel).queryByTestId('format-strip-cell-conversion_rate')).toBeNull()
    expect(within(carousel).getByTestId('format-strip-cell-ctr')).toBeInTheDocument()
    expect(strip.textContent).not.toMatch(/—|تكلفة النتيجة|الإنفاق|العائد/)
    expect(within(strip).getByTestId('format-strip-winner')).toHaveTextContent('الأنسب للهدف')
    // ROAS was answered by no format: no line for it on any card.
    expect(within(strip).queryAllByTestId('format-strip-cell-roas')).toHaveLength(0)
  })

  it('an internal reader sees the cost of a result; several objectives share ONE block, a labelled row each', () => {
    const data = { audience: 'internal', report_sections: ['content_format_comparison'], content_format_comparison: payload([sales(formats), traffic(formats)]) }
    renderWithProviders(<FormatComparisonReportBlock payload={data} ar={false} />, { locale: 'en' })

    expect(screen.getAllByTestId('report-format-strip')).toHaveLength(1)
    expect(screen.getByTestId('format-strip-objective-sales')).toHaveTextContent('Sales')
    expect(screen.getByTestId('format-strip-objective-traffic')).toHaveTextContent('Traffic')
    expect(within(screen.getByTestId('format-strip-objective-sales')).getAllByTestId('format-strip-cell-cost_per_result').length).toBeGreaterThan(0)
  })

  it('adds no page — not even a title — when the reader has no figure to read (a client copy answered only in cost)', () => {
    const onlyCost = sales([row('video', { results: null, ctr: null, conversion_rate: null, roas: null }), row('image', { results: 0, ctr: 0, conversion_rate: 0, roas: null })])
    const client = { audience: 'client', report_sections: ['content_format_comparison'], content_format_comparison: payload([onlyCost], { spend_hidden: true }) }
    expect(withFormatComparisonSlides<Page>([], client)).toEqual([])
    const page = renderWithProviders(<FormatComparisonSlide data={client} />, { locale: 'ar' })
    expect(screen.queryByTestId('slide-format-comparison')).toBeNull()
    page.unmount()
    // The same copy for an internal reader has the cost to show: one page.
    expect(withFormatComparisonSlides<Page>([], { ...client, audience: 'internal', content_format_comparison: payload([onlyCost]) })).toHaveLength(1)
  })

  it('draws nothing where the server did not name the part', () => {
    const data = { audience: 'client', report_sections: ['kpis'], content_format_comparison: payload([sales(formats)]) }
    renderWithProviders(<FormatComparisonReportBlock payload={data} ar />, { locale: 'ar' })
    expect(screen.queryByTestId('report-format-comparison')).toBeNull()
  })
})
