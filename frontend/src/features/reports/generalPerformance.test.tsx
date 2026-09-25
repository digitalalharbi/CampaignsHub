import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SlideBody } from './InteractiveReport'

/**
 * REPORT-GENERAL-PERFORMANCE-001 — «الأداء المباشر» and «الأداء المدمج» leave the client's report.
 *
 * ## Why they were there
 *
 * They answer a real question an ANALYST asks: what did the sales campaigns cost per order, and what
 * did the whole programme cost per order. Two numbers for two questions, and the slide was careful
 * to say they were not interchangeable.
 *
 * ## Why they cannot stay
 *
 * They are internal vocabulary on a page a client reads. «مدمج» is a word about how WE compute, not
 * about what happened to their money, and a reader who does not already hold the distinction reads
 * two costs per order and believes one of them is wrong. The owner's instruction is to stop
 * presenting the CONCEPT rather than to rename it, so the replacement is not «الأداء المباشر» with
 * a nicer label: it is «الأداء العام» — what the whole programme delivered, in figures that stay
 * meaningful when campaigns have different goals — with each objective family answering for its own
 * results underneath.
 *
 * ## The rule this pins
 *
 * General Performance may carry only metrics that survive mixed objectives: spend, impressions,
 * clicks, CTR, CPM. It must NOT carry a universal «results» figure, because purchases + leads +
 * video views is not a quantity. Orders belong to «المبيعات والتحويلات» and nowhere else.
 *
 * ## Old saved reports keep working
 *
 * A report generated last quarter holds `objective_performance` with `direct` and `blended` keys.
 * Those keys are its data and deleting support for them would break a client's archive, so the
 * payload is read exactly as before and only the PRESENTATION changes.
 */
const meta = { reportName: 'R', platforms: [] }
const slide = { id: 's', type: 'objective_performance', order: 1, visible: true } as never

/** A legacy payload, in the shape a report saved before this change holds. */
const legacy = {
  currency: 'SAR',
  objective_performance: {
    direct: {
      label_ar: 'الأداء المباشر', label_en: 'Direct performance',
      spend: 600, orders: 24, revenue: 2400, cpa: 25, roas: 4, aov: 100,
      formula: { cpa: 'sales-path spend ÷ sales-path orders', roas: 'sales-attributed revenue ÷ sales-path spend' },
      excluded_spend: 400,
      included_campaigns: [], excluded_campaigns: [],
    },
    blended: {
      label_ar: 'الأداء المدمج', label_en: 'Blended performance',
      spend: 1000, orders: 24, revenue: 2400, blended_cpa: 41.6, blended_roas: 2.4,
      formula: { blended_cpa: 'spend on EVERY path ÷ sales-path orders', blended_roas: 'x' },
      includes_non_sales_spend: 400,
      never_substitutes_direct: true,
    },
    paths: [
      {
        path: 'conversion', label_ar: 'المبيعات والتحويلات', spend: 600, cpa: 25, roas: 4, cpc: null, cpm: null,
        result_metrics_apply: true, cpa_mixes_result_types: false, result_composition: [],
        campaigns: [], campaigns_count: 3,
      },
      {
        path: 'traffic', label_ar: 'الزيارات', spend: 250, cpa: null, roas: null, cpc: 1.4, cpm: null,
        result_metrics_apply: false, cpa_mixes_result_types: false, result_composition: [],
        campaigns: [], campaigns_count: 2,
      },
      {
        path: 'awareness', label_ar: 'الوعي', spend: 150, cpa: null, roas: null, cpc: null, cpm: 12,
        result_metrics_apply: false, cpa_mixes_result_types: false, result_composition: [],
        campaigns: [], campaigns_count: 1,
      },
    ],
  },
  objective_analytics: null,
} as never

/** Every word the client's report may no longer speak. */
const FORBIDDEN = ['الأداء المباشر', 'الأداء المدمج', 'المدمج', 'Blended', 'blended', 'Direct performance']

describe('the client report’s general performance section', () => {
  /** **The defect, pinned.** None of the internal vocabulary reaches the page. */
  it('never speaks of direct or blended performance', () => {
    render(<SlideBody slide={slide} data={legacy} meta={meta} />)

    const text = document.body.textContent ?? ''
    for (const word of FORBIDDEN) {
      expect(text, `«${word}» reached a page a client reads`).not.toContain(word)
    }
  })

  /** What replaces them, by name. */
  it('is titled «الأداء العام»', () => {
    render(<SlideBody slide={slide} data={legacy} meta={meta} />)

    expect(screen.getByTestId('general-performance')).toBeInTheDocument()
    expect(document.body.textContent).toContain('الأداء العام')
  })

  /**
   * General Performance describes DELIVERY, and delivery only.
   *
   * The programme spent 1,000 across three different goals. A «results» figure over that is
   * purchases plus visits plus impressions, which is not a quantity — so the section states the
   * portfolio's spend and nothing that pretends to be one outcome.
   */
  it('states the whole programme’s spend without inventing a universal result', () => {
    render(<SlideBody slide={slide} data={legacy} meta={meta} />)

    const general = screen.getByTestId('general-performance')
    // The programme's whole spend, in the product's own money notation (1K, not 1,000).
    expect(general.textContent).toMatch(/1(\.0)?K/)
    // The old blended cost per order must not survive as a portfolio headline under a new name.
    expect(general.textContent).not.toContain('41.6')
  })

  /**
   * Orders and their cost live in the objective family that measures them.
   *
   * This is where «24 طلب at 25 each» belongs — beside the 600 that bought them, not beside the
   * 1,000 the programme spent on three different goals.
   */
  it('answers for orders inside المبيعات والتحويلات, against that family’s own spend', () => {
    render(<SlideBody slide={slide} data={legacy} meta={meta} />)

    const sales = screen.getByTestId('objective-family-conversion')
    expect(sales.textContent).toContain('المبيعات والتحويلات')
    expect(sales.textContent).toContain('600')
  })

  /** A family with no sales results is judged by its own metric, never by a cost per order. */
  it('judges traffic and awareness by their own metrics', () => {
    render(<SlideBody slide={slide} data={legacy} meta={meta} />)

    expect(screen.getByTestId('objective-family-traffic')).toBeInTheDocument()
    expect(screen.getByTestId('objective-family-awareness')).toBeInTheDocument()
    // No cost-per-order is claimed for a family that never set out to sell.
    expect(screen.getByTestId('objective-family-awareness').textContent).not.toContain('CPA')
  })

  /** A report saved before this change still renders — its keys are its data. */
  it('renders a legacy payload rather than refusing it', () => {
    render(<SlideBody slide={slide} data={legacy} meta={meta} />)

    expect(screen.getByTestId('general-performance')).toBeInTheDocument()
    expect(screen.queryByText(/أعد توليد التقرير/)).not.toBeInTheDocument()
  })
})
