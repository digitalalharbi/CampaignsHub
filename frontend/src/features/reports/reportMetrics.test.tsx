import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SlideBody, type ReportData, type Slide } from './InteractiveReport'
import { creativeChips, creativeReadings, previousReading, reportMetrics, trendSeries } from './reportMetrics'

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

  it('still leads a sales report with the return, named the way a client reads it', () => {
    render(<SlideBody slide={slide} data={sales} meta={meta} />)

    // METRIC-NAMES-001 — «ROAS» is jargon to everyone outside media buying, and it is the figure a
    // client is most likely to repeat back to somebody else.
    expect(screen.getByText('العائد على الإنفاق')).toBeInTheDocument()
    expect(screen.getByText('أفضل منصة (ROAS)')).toBeInTheDocument()
  })

  /**
   * The exact figure is selectable text, which is what a PDF reader extracts — so a figure printed
   * there is the most durable form of the claim, and a figure for a metric nobody reported would be
   * the most durable form of a false one.
   *
   * It now lives under the headline it makes exact rather than in a strip below the grid, so the
   * assertion reads every card's exact line instead of one shared row. What it checks is unchanged:
   * a metric this account never bought has no exact figure anywhere on the slide.
   */
  it('never writes an unreported metric as an exact figure', () => {
    const { container } = render(<SlideBody slide={slide} data={brand} meta={meta} />)
    const exact = [...container.querySelectorAll('[data-exact]')].map((n) => n.textContent ?? '')

    expect(exact.length, 'the cards carry no exact-figure row at all').toBeGreaterThan(0)

    // An exact row either states a figure or says nothing: a "—" here repeats the headline's own
    // «nothing was reported» in smaller type, which is a row that costs a glance and carries nothing.
    for (const line of exact) {
      const text = line.trim()

      if (text !== '') expect(text, 'an exact row that states no figure').toMatch(/\d/)
      expect(text, 'a dash is not an exact figure').not.toBe('—')
    }

    /*
     * The card for a metric the platform never sent NAMES it and says so — «مشاهدات الفيديو / لم
     * ترسله المنصة» is the honest statement, and the old strip could not make it because a strip has
     * no room for a sentence. What must not happen is a precise FIGURE for it: that is the durable,
     * selectable form of a number nobody measured.
     */
    const unreported = [...container.querySelectorAll('[data-exact]')]
      .map((n) => n.parentElement?.textContent ?? '')
      .find((card) => card.includes('مشاهدات الفيديو'))

    expect(unreported, 'the unreported card is not on the slide at all').toBeDefined()
    expect(unreported).toContain('لم ترسله المنصة')

    const card = [...container.querySelectorAll('[data-exact]')]
      .find((n) => (n.parentElement?.textContent ?? '').includes('مشاهدات الفيديو'))

    expect(card?.textContent?.trim(), 'an exact figure was written for a metric nobody reported').toBe('')
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

/**
 * §14.7 — the comparison table, and the scope trap inside it.
 *
 * `previous.cpa` is the BLENDED cost per order of the earlier window. Setting it beside this
 * period's Direct CPA compares two different sets of campaigns under one heading, and the
 * difference between them is not a change in performance. Caught live: 75 against a blended 87
 * where the honest previous Direct figure was 80.
 */
describe('the period comparison', () => {
  const split = {
    ...sales,
    previous: { spend: 15000, revenue: 60000, conversions: 500, cpa: 87.42, roas: 4.0, ctr: 0.025 },
    objective_performance: {
      paths: [],
      direct: { label_ar: '', label_en: '', spend: 5000, orders: 250, revenue: 40000, cpa: 74.54, roas: 9.82, aov: 160, formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [] },
      blended: { label_ar: '', label_en: '', spend: 20000, orders: 700, revenue: 80000, blended_cpa: 28.57, blended_roas: 4, formula: { blended_cpa: '', blended_roas: '' }, includes_non_sales_spend: 15000 },
    },
    objective_performance_previous: {
      paths: [],
      direct: { label_ar: '', label_en: '', spend: 4000, orders: 200, revenue: 30000, cpa: 80.21, roas: 11.28, aov: 150, formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [] },
      blended: { label_ar: '', label_en: '', spend: 15000, orders: 500, revenue: 60000, blended_cpa: 87.42, blended_roas: 4, formula: { blended_cpa: '', blended_roas: '' }, includes_non_sales_spend: 11000 },
    },
  } as ReportData

  it('compares a direct figure with the previous period’s direct figure, not the blended one', () => {
    const cpa = reportMetrics(split).find((m) => m.key === 'cpa')!
    const before = previousReading(cpa, split)

    expect(cpa.substituted).toBe(true)
    // The cell is formatted like the card it sits under — the precision lives in the exact strip.
    expect(before.text).toBe('80 SAR')
    /*
     * The change is the discriminator, and it is why this test exists. Direct against Direct is
     * 74.54 ÷ 80.21 — a 7% improvement. Against the blended 87.42 it would read as 15%, and half of
     * that «improvement» would be the difference between which campaigns each figure counted.
     */
    expect(before.change).toBeCloseTo(-0.0707, 3)
    expect(before.change).not.toBeCloseTo(-0.1473, 3)
  })

  it('compares an ordinary metric with the ordinary previous total', () => {
    const spend = reportMetrics(split).find((m) => m.key === 'spend')!

    expect(spend.substituted).toBe(false)
    expect(previousReading(spend, split).text).toBe('15K SAR')
  })

  it('leaves the comparison empty rather than inventing one when the previous period is absent', () => {
    const { previous: _p, objective_performance_previous: _o, ...alone } = split
    const cpa = reportMetrics(alone as ReportData).find((m) => m.key === 'cpa')!

    expect(previousReading(cpa, alone as ReportData)).toEqual({ text: null, change: null })
  })

  it('renders the table with both periods side by side', () => {
    render(<SlideBody slide={{ id: 'comparison', type: 'comparison', order: 1, visible: true }} data={split} meta={meta} />)

    expect(screen.getByText('المقارنات والاتجاهات')).toBeInTheDocument()
    expect(screen.getByText('80 SAR')).toBeInTheDocument()
    // The blended previous figure must not appear anywhere in the table.
    expect(screen.queryByText('87 SAR')).not.toBeInTheDocument()
    expect(screen.getByText('11.28×')).toBeInTheDocument()
  })
})

/** §14.7 — the notes slide says nothing rather than filling itself with generic advice. */
describe('the observations slide', () => {
  it('states plainly that there is nothing to flag', () => {
    render(<SlideBody slide={{ id: 'observations', type: 'observations', order: 1, visible: true }} data={brand} meta={meta} />)

    expect(screen.getByText('لا توجد ملاحظات تستدعي الانتباه في هذه الفترة.')).toBeInTheDocument()
  })

  it('renders each derived note with the figures that produced it', () => {
    const withNotes = {
      ...brand,
      observations: [
        { id: 'a', kind: 'budget_pace', severity: 'critical' as const, title: 'حملة «الصيف» تستهلك الميزانية أسرع من الخطة', detail: 'صُرف 8,000.00 SAR من أصل 10,000.00 SAR.', value: '1.60×', scope: { type: 'campaign', name: 'الصيف' } },
      ],
    }
    render(<SlideBody slide={{ id: 'observations', type: 'observations', order: 1, visible: true }} data={withNotes} meta={meta} />)

    expect(screen.getByText('1.60×')).toBeInTheDocument()
    expect(screen.getByText('صُرف 8,000.00 SAR من أصل 10,000.00 SAR.')).toBeInTheDocument()
  })
})

/** §14.10 — always rendered, so its absence never has to be interpreted. */
describe('the data quality slide', () => {
  it('reports a healthy state as clearly as a broken one', () => {
    const fresh = { ...brand, freshness: { state: 'fresh', last_sync_at: '2026-08-07T09:00:00Z', missing_days: 0, sources: [{ name: 'Meta Ads', provider: 'meta', state: 'fresh', last_sync_at: '2026-08-07T09:00:00Z' }] } }
    render(<SlideBody slide={{ id: 'data_quality', type: 'data_quality', order: 1, visible: true }} data={fresh} meta={meta} />)

    // Twice: the overall state, and the one source behind it.
    expect(screen.getAllByText('محدثة')).toHaveLength(2)
    expect(screen.getByText('Meta Ads')).toBeInTheDocument()
  })

  it('shows a missing day count of «—» rather than 0 when nothing measured it', () => {
    const unknown = { ...brand, freshness: { state: 'unknown', last_sync_at: null, missing_days: null } }
    const { container } = render(<SlideBody slide={{ id: 'data_quality', type: 'data_quality', order: 1, visible: true }} data={unknown} meta={meta} />)

    expect(container.textContent).toContain('أيام بلا بيانات')
    expect(container.textContent).not.toContain('أيام بلا بيانات0')
  })
})

/**
 * §14.8 — content is judged on what it was made to do.
 *
 * `CreativeRankingService` has ranked by objective since it was written, and the CARDS then
 * labelled every winner «ROAS —» and «CPA —». A brand report therefore ordered its content
 * correctly and presented each one as a failure.
 */
describe('creative analysis by objective', () => {
  it('compares brand content on attention, and sales content on the return', () => {
    expect(creativeChips('awareness')).toEqual(['reach', 'cpm', 'impressions', 'engagements'])
    expect(creativeChips('traffic')).toEqual(['ctr', 'cpc', 'landing_page_views', 'clicks'])
    expect(creativeChips('leads')).toContain('cpa')
    expect(creativeChips('sales')).toContain('roas')
  })

  it('never puts a return or a cost per order on brand content', () => {
    expect(creativeChips('awareness')).not.toContain('roas')
    expect(creativeChips('awareness')).not.toContain('cpa')
    expect(creativeChips('video')).not.toContain('roas')
  })

  it('mixed objectives get operational chips only', () => {
    const mixed = creativeChips('custom')

    expect(mixed).not.toContain('roas')
    expect(mixed).not.toContain('cpa')
    expect(mixed).toContain('clicks')
  })

  /** A chip for a metric the creative's own platform never sends is a state, not a zero. */
  it('reads an unreported chip as a state', () => {
    const row = { campaign_name: 'حملة', reach: 0, cpm: 12, impressions: 400000, engagements: 0 }
    const readings = creativeReadings(row, 'awareness', { reach: false, cpm: true, impressions: true, engagements: false }, true, 'SAR')

    expect(readings.find((r) => r.key === 'reach')!.reading).toEqual({ kind: 'not_provided' })
    expect(readings.find((r) => r.key === 'cpm')!.reading).toEqual({ kind: 'value', text: '12 SAR' })
  })

  it('renders the brand report’s creative card without a ROAS chip', () => {
    const withCreatives = {
      ...brand,
      top_creatives: [{ provider: 'meta', campaign_name: 'حملة الوعي', reach: 900000, cpm: 4, impressions: 6_000_000, engagements: 12000, reason: 'أعلى مدى بأقل CPM.' }],
    }
    render(<SlideBody slide={{ id: 'c', type: 'top_creatives', platform: 'meta', order: 1, visible: true }} data={withCreatives} meta={meta} />)

    expect(screen.getByText('حملة الوعي')).toBeInTheDocument()
    expect(screen.getByText('الوصول')).toBeInTheDocument()
    expect(screen.queryByText('ROAS')).not.toBeInTheDocument()
    expect(screen.queryByText('CPA')).not.toBeInTheDocument()
  })
})


/**
 * «توحيد المؤشرات بالنظام البطاقات» — one metric, one card, and a row that reads as a row.
 *
 * jsdom does not lay anything out, so these assert the STRUCTURE that produces the alignment rather
 * than the pixels: the card is a full-height column and its chart row is pushed to the bottom edge.
 * Without both, a card with a note stands taller than its neighbour and the six sparklines float at
 * six different heights — which is the thing the owner asked to be fixed, and the thing a class that
 * silently stops applying would bring back. The measured proof is in the browser: at 1440 all six
 * cards report height 126, bottom 1337, and their chart rows all start at 1294.
 */
describe('the executive cards line up', () => {
  // The same slide the file already builds — see the top of this file.
  it('gives every card a full-height column with its chart on the bottom edge', () => {
    const { container } = render(<SlideBody slide={slide} data={brand} meta={meta} />)
    const cards = [...container.querySelectorAll('[data-exact]')].map((n) => n.parentElement!)

    expect(cards.length, 'the executive slide drew no cards').toBeGreaterThan(3)

    for (const card of cards) {
      expect(card.className, 'a card that is not a full-height column cannot share a row').toContain('h-full')
      expect(card.className).toContain('flex-col')

      const foot = card.lastElementChild!

      expect(foot.className, 'the chart row must be pushed to the bottom, or the baselines drift').toContain('mt-auto')
    }
  })

  /** The figures live in the cards, not in a second list underneath them. */
  it('states each exact figure inside its own card', () => {
    const { container } = render(<SlideBody slide={slide} data={brand} meta={meta} />)

    for (const row of container.querySelectorAll('[data-exact]')) {
      expect(row.parentElement?.className, 'an exact figure outside a card is the strip coming back').toContain('rounded-2xl')
    }
  })
})
