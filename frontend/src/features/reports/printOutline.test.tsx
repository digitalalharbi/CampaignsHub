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
  campaigns: [{ name: 'Eid', platform: 'meta', status: 'active', spend: 1000, results: 20, cpa: 50 }],
  recommendations: [],
}

const outline = (over: Record<string, unknown>[] = []) => [
  { key: 'executive_summary', title_ar: 'الملخّص', title_en: 'Executive summary', present: true, absent_reason: null },
  { key: 'platforms', title_ar: 'المنصات', title_en: 'Platform breakdown', present: true, absent_reason: null },
  { key: 'campaigns', title_ar: 'الحملات', title_en: 'Campaigns', present: true, absent_reason: null },
  ...over,
]

describe('the printed document’s sections', () => {
  it('takes their titles and their order from the outline', () => {
    render(<PrintDocument data={{ ...base, outline: outline() } as never} currency="SAR" reportName="R" clientName="C" />)

    expect(screen.getByText('1. Executive summary')).toBeInTheDocument()
    expect(screen.getByText('2. Platform breakdown')).toBeInTheDocument()
    expect(screen.getByText('3. Campaigns')).toBeInTheDocument()
  })

  /**
   * An absent section prints the generator's reason instead of an empty table — and does not take a
   * number, because the numbering is over what is actually printed.
   */
  it('prints why a section is absent, and does not number it', () => {
    const withAbsent = [
      outline()[0]!,
      {
        key: 'platforms',
        title_ar: 'المنصات',
        title_en: 'Platform breakdown',
        present: false,
        absent_reason: 'no_platform_reported_in_this_window',
        absent_reason_en: 'No platform reported figures in this window.',
      },
      outline()[2]!,
    ]

    render(<PrintDocument data={{ ...base, platforms: [], outline: withAbsent } as never} currency="SAR" reportName="R" clientName="C" />)

    expect(screen.getByText('No platform reported figures in this window.')).toBeInTheDocument()
    expect(screen.queryByText('2. Platform breakdown')).not.toBeInTheDocument()
    // Campaigns is the second thing actually printed, so it is numbered 2.
    expect(screen.getByText('2. Campaigns')).toBeInTheDocument()
  })

  /** A snapshot written before the outline existed still prints, with its old headings. */
  it('falls back to its own titles when a report has no outline', () => {
    render(<PrintDocument data={base as never} currency="SAR" reportName="R" clientName="C" />)

    expect(screen.getByText('Executive Summary')).toBeInTheDocument()
    expect(screen.getByText('Campaigns')).toBeInTheDocument()
  })
})
