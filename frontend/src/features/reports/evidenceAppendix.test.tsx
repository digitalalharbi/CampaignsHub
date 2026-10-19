import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { withEvidenceSlides, type PrintEvidence } from './reportSections'

/**
 * WD-EVIDENCE-001 — the PDF's appendix of platform screenshots: drawn from the URLs the print payload
 * names (fetched with the print token), a PDF attachment named rather than drawn, and nothing at all
 * when the server attached none.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const shot = (id: string, over: Partial<PrintEvidence> = {}): PrintEvidence => ({
  id, provider: 'meta', period_start: '2026-08-01', period_end: '2026-08-31', captured_at: null, note: 'Ads Manager', name: `${id}.png`, image: true, url: `/api/v1/reports/print/t/evidence/${id}`, ...over,
})

const doc = (evidence?: PrintEvidence[]) =>
  render(<PrintDocument data={{ period: { from: '2026-08-01', to: '2026-08-31' }, kpis: {}, summary: [], platforms: [], recommendations: [], outline: [], ...(evidence ? { platform_evidence: evidence } : {}) } as never} currency="SAR" reportName="R" clientName="C" />)

type Page = { id: string; type: string; order: number; visible: boolean; range?: [number, number] }

describe('the PDF evidence appendix', () => {
  it('draws each screenshot from its token URL and names a PDF attachment', () => {
    doc([shot('a'), shot('b', { image: false, url: null, name: 'invoice.pdf' })])

    expect(screen.getByTestId('print-evidence-a').querySelector('img')).toHaveAttribute('src', '/api/v1/reports/print/t/evidence/a')
    expect(screen.getByTestId('print-evidence-b').querySelector('img')).toBeNull()
    expect(screen.getByTestId('print-evidence-b')).toHaveTextContent('invoice.pdf')
  })

  it('has no appendix when the server attached none', () => {
    doc()
    expect(screen.queryByTestId('print-evidence')).toBeNull()
  })

  it('gives the deck one fixed page per screenshot, after the report', () => {
    expect(withEvidenceSlides<Page>([], { platform_evidence: [shot('a'), shot('b')] }).map((s) => [s.type, s.range])).toEqual([['__evidence', [0, 1]], ['__evidence', [1, 2]]])
    expect(withEvidenceSlides<Page>([], {})).toEqual([])
  })
})
