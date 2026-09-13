import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SlideBody, type ReportData, type Slide } from './InteractiveReport'

/**
 * REPORT-SUMMARY-DECISION-001 — a summary is shorter by DEPTH, and says so.
 *
 * Restoring recommendations and next steps to the summary form is only half the contract. A
 * decision-length report that then prints eleven of them is a full report with a shorter cover — and
 * one that prints three without mentioning the other eight teaches its reader that three is all
 * there are. Both failures land on somebody who acts on the list.
 */
const note = (title: string) => ({ title, detail: 'تفصيل.', status: 'approved', type: 'recommendation' })

const base: ReportData = {
  period: { from: '2026-07-01', to: '2026-07-31' },
  currency: 'SAR',
  objective: 'sales',
  metric_set: ['spend', 'revenue'],
  kpis: { spend: 100, revenue: 400 },
  reported: { spend: true, revenue: true },
  delta: {},
  timeseries: [],
  platforms: [],
  campaigns: [],
  best: { basis: null, platform: null, platform_value: null, platform_by_roas: null, platform_by_cpa: null, campaign: null },
  slides: [],
  findings: [note('أ'), note('ب'), note('ج'), note('د'), note('هـ')],
  recommendations: [note('١'), note('٢'), note('٣'), note('٤')],
} as unknown as ReportData

const slide: Slide = { id: 'recommendations', type: 'recommendations', order: 1, visible: true }
const meta = { reportName: 'تقرير', platforms: ['meta'] }

describe('what a summary does with a long list', () => {
  it('shows the few at the top and states how many it is not showing', () => {
    render(<SlideBody slide={slide} data={{ ...base, form: 'executive_summary' } as ReportData} meta={meta} />)

    expect(screen.getByText('أ')).toBeInTheDocument()
    expect(screen.getByText('ج')).toBeInTheDocument()
    // The fourth finding is held back...
    expect(screen.queryByText('د')).not.toBeInTheDocument()
    // ...and the reader is told, rather than left to assume three is all there is.
    const notes = screen.getAllByTestId('summary-trimmed-note')
    expect(notes.length).toBeGreaterThan(0)
    expect(notes.map((n) => n.textContent).join(' ')).toMatch(/التقرير التفصيلي/)
  })

  it('gives the full report all of them, and says nothing about trimming', () => {
    render(<SlideBody slide={slide} data={{ ...base, form: 'detailed' } as ReportData} meta={meta} />)

    for (const title of ['أ', 'ب', 'ج', 'د', 'هـ', '١', '٢', '٣', '٤']) {
      expect(screen.getByText(title)).toBeInTheDocument()
    }
    expect(screen.queryByTestId('summary-trimmed-note')).not.toBeInTheDocument()
  })

  it('says nothing about trimming when a summary is already short', () => {
    const short = { ...base, form: 'executive_summary', findings: [note('أ')], recommendations: [note('١')] } as ReportData
    render(<SlideBody slide={slide} data={short} meta={meta} />)

    expect(screen.getByText('أ')).toBeInTheDocument()
    expect(screen.queryByTestId('summary-trimmed-note')).not.toBeInTheDocument()
  })
})
