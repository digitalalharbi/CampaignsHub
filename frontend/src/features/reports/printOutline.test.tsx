import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — the printed document takes its sections from the report's own outline.
 *
 * The numbering was written into this file — «1. Executive Summary», «2. Platform Performance» — so a
 * report whose generator had nothing to say about platforms still printed the heading with an empty
 * table beneath it, and the numbers stepped over whatever had been skipped.
 *
 * What an empty table says to a client and what «no platform reported figures in this window» says
 * are different statements, and only one of them is true.
 */
/*
  jsdom has no font loading API, and the document signals its print-readiness through
  `document.fonts.ready`. A stub is the whole of what this needs: these tests are about which
  sections print, not about when the renderer is told they are ready.
*/
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const base = {
  period: { from: '2026-08-01', to: '2026-08-30' },
  kpis: { spend: 1000, revenue: 4000 },
  summary: ['A good month.'],
  platforms: [{ platform: 'meta', spend: 1000, revenue: 4000, results: 20, roas: 4 }],
  /*
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — the campaign section is not a section any more.
   *
   * `objectives` takes its place in the fixtures below: it is where the outline's fifth entry went,
   * it is a section this document actually draws, and the numbering claims are about what is
   * PRINTED, so they need a real one to count.
   */
  objective_performance: {
    paths: [{ path: 'conversion', label_ar: 'التحويل', label_en: 'Conversion & sales', spend: 1000, orders: 20, cpa: 50, roas: 4, result_metrics_apply: true, campaigns: [] }],
    direct: { spend: 1000, orders: 20, revenue: 4000, cpa: 50, roas: 4, formula: { cpa: '', roas: '' }, included_campaigns: [], excluded_campaigns: [] },
    blended: { spend: 1000, orders: 20, revenue: 4000, blended_cpa: 50, blended_roas: 4, formula: { blended_cpa: '', blended_roas: '' }, includes_non_sales_spend: 0 },
  },
  recommendations: [],
}

/* The generator's own order — objectives BEFORE platforms (CLIENT-FACING-PRESENTATION-001). */
const outline = (over: Record<string, unknown>[] = []) => [
  { key: 'executive_summary', title_ar: 'الملخّص', title_en: 'Executive summary', present: true, absent_reason: null },
  { key: 'objectives', title_ar: 'الأهداف', title_en: 'Breakdown by objective', present: true, absent_reason: null },
  { key: 'platforms', title_ar: 'المنصات', title_en: 'Platform breakdown', present: true, absent_reason: null },
  ...over,
]

describe('the printed document’s sections', () => {
  it('takes their titles and their order from the outline', () => {
    render(<PrintDocument data={{ ...base, outline: outline() } as never} currency="SAR" reportName="R" clientName="C" />)

    expect(screen.getByText('1. Executive summary')).toBeInTheDocument()
    expect(screen.getByText('2. Breakdown by objective')).toBeInTheDocument()
    expect(screen.getByText('3. Platform breakdown')).toBeInTheDocument()
  })

  /**
   * An absent section prints the generator's reason instead of an empty table — and does not take a
   * number, because the numbering is over what is actually printed.
   */
  it('prints why a section is absent, and does not number it', () => {
    const withAbsent = [
      outline()[0]!,
      outline()[1]!,
      {
        key: 'platforms',
        title_ar: 'المنصات',
        title_en: 'Platform breakdown',
        present: false,
        absent_reason: 'no_platform_reported_in_this_window',
        absent_reason_en: 'No platform reported figures in this window.',
      },
    ]

    render(<PrintDocument data={{ ...base, platforms: [], outline: withAbsent } as never} currency="SAR" reportName="R" clientName="C" />)

    expect(screen.getByText('No platform reported figures in this window.')).toBeInTheDocument()
    expect(screen.queryByText('3. Platform breakdown')).not.toBeInTheDocument()
    expect(screen.queryByText('Platform breakdown', { exact: true })).toBeInTheDocument()
    // The objective split is still the second thing printed; nothing after the absent section moves up into its place either.
    expect(screen.getByText('2. Breakdown by objective')).toBeInTheDocument()
  })

  /**
   * The numbering is over what is PRINTED — and the generator's outline lists more than this
   * document prints.
   *
   * `ReportStructure` emits `performance` second (and `findings` seventh) whenever a window has
   * figures, and this document prints neither as a numbered section: the KPI block sits under the
   * executive summary, and findings travel with the recommendations. Counting over the outline
   * therefore printed «1. Executive summary» and then «3. Platform breakdown» on every real report
   * — the «stepped over» defect this file was written against, in its other direction. The fixture
   * above never showed it because its outline carried only the three keys the page prints.
   */
  it('numbers consecutively when the outline lists sections this document does not print', () => {
    const generatorOrder = [
      outline()[0]!,
      { key: 'performance', title_ar: 'الأداء العام', title_en: 'Overall performance', present: true, absent_reason: null },
      outline()[1]!,
      outline()[2]!,
      { key: 'findings', title_ar: 'النتائج', title_en: 'Findings', present: true, absent_reason: null },
      { key: 'recommendations', title_ar: 'التوصيات', title_en: 'Recommendations', present: true, absent_reason: null },
    ]

    render(<PrintDocument data={{ ...base, outline: generatorOrder, recommendations: ['Shift budget to Meta.'] } as never} currency="SAR" reportName="R" clientName="C" />)

    const numbered = screen
      .getAllByRole('heading', { level: 2 })
      .map((h) => h.textContent ?? '')
      .filter((t) => /^\d+\. /.test(t))

    expect(numbered).toEqual(['1. Executive summary', '2. Breakdown by objective', '3. Platform breakdown', '4. Recommendations'])
  })

  /** A snapshot written before the outline existed still prints, with its old headings. */
  it('falls back to its own titles when a report has no outline', () => {
    render(<PrintDocument data={base as never} currency="SAR" reportName="R" clientName="C" />)

    expect(screen.getByText('Executive Summary')).toBeInTheDocument()
    expect(screen.getByText('Platform Performance')).toBeInTheDocument()
  })

  /**
   * CLIENT-REPORT-ENTITY-BOUNDARY-001 — no printed document has a campaign section, outline or not.
   *
   * The fallback path matters as much as the outline one: every snapshot generated before this
   * requirement still carries `campaigns`, and it is still printed through this component when a
   * client opens an old PDF. Neither route may draw the roster.
   */
  it('prints no campaign section, even from a snapshot that still carries one', () => {
    const legacy = {
      ...base,
      campaigns: [{ name: 'Eid — burner', platform: 'meta', status: 'active', spend: 1000, results: 20, cpa: 50 }],
    }

    const { container } = render(
      <PrintDocument data={legacy as never} currency="SAR" reportName="R" clientName="C" />,
    )

    expect(container.textContent).not.toContain('Eid')
    expect(screen.queryByText('Campaigns')).not.toBeInTheDocument()
  })
})
