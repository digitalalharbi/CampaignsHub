import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { InteractiveReport } from './InteractiveReport'
import { REPORT_NOTE, REPORT_NOTE_MORE, ReportFooter, reportFooterItems } from './reportFooter'

/**
 * REPORT-FOOTER-001 — one subtle footer, the same on every surface; never a section.
 */
describe('the report footer', () => {
  const facts = { updatedAt: '2026-10-16T10:30:00Z', period: { from: '2026-09-16', to: '2026-10-15' }, currency: 'USD', timezone: 'Asia/Riyadh', objective: 'leads' }

  it('states the Owner’s exact note and the five facts under it, in order', () => {
    render(<ReportFooter locale="ar" facts={facts} />)

    expect(screen.getByTestId('report-footer-note')).toHaveTextContent(REPORT_NOTE.ar)
    expect(REPORT_NOTE.ar).toBe('ملاحظة: تُدار الحملات وفق منهجية قائمة على الأداء، وقد تختلف النتائج بحسب الفترة والمنصة ومصدر البيانات ونموذج الإسناد ومرحلة تعلّم الحملة.')
    const meta = screen.getByTestId('report-footer-meta').textContent ?? ''
    const order = ['آخر تحديث', 'الفترة', 'العملة', 'المنطقة الزمنية', 'هدف الحملة'].map((l) => meta.indexOf(l))
    expect(order.every((i) => i >= 0)).toBe(true)
    expect([...order].sort((a, b) => a - b)).toEqual(order)
  })

  it('leaves out a fact that has no value instead of printing a dash', () => {
    const labels = reportFooterItems({ ...facts, objective: null, timezone: null }, 'ar').map(([k]) => k)

    expect(labels).toEqual(['آخر تحديث', 'الفترة', 'العملة'])
  })

  it('keeps the long explanation behind «معرفة المزيد» and shows it only when asked', () => {
    render(<ReportFooter locale="ar" facts={facts} />)

    expect(screen.queryByTestId('report-footer-more')).toBeNull()
    fireEvent.click(screen.getByTestId('report-footer-toggle'))
    expect(screen.getByTestId('report-footer-more')).toHaveTextContent(REPORT_NOTE_MORE.ar)
    expect(screen.getByTestId('report-footer-toggle')).toHaveTextContent('إخفاء')
    fireEvent.click(screen.getByTestId('report-footer-toggle'))
    expect(screen.queryByTestId('report-footer-more')).toBeNull()
  })

  /*
   * REPORT-FOOTER-IN-SYSTEM-CARD-001 (Owner, 2026-10-19) — the report ends like the product: the note in the
   * same card the Analytics page uses, and the facts underneath as their own row of matching cards.
   */
  it('is the in-system note card with the facts as their own row beneath it', () => {
    const { container } = render(<ReportFooter locale="en" facts={facts} />)
    const footer = screen.getByTestId('report-footer')
    const card = screen.getByTestId('report-footer-note-card')
    const meta = screen.getByTestId('report-footer-meta')

    expect(container.querySelector('h1,h2,h3,h4')).toBeNull()
    expect(footer.className).toMatch(/\bw-full\b/)
    expect(footer.className).not.toMatch(/max-w-|mx-auto|text-center/)
    // The very classes PerformanceNotice's compact card carries — not a look-alike.
    expect(card.className).toMatch(/rounded-xl/)
    expect(card.className).toMatch(/\bborder\b/)
    expect(card.className).toMatch(/bg-surface-secondary/)
    expect(card.querySelector('svg')).not.toBeNull()
    expect(screen.getByTestId('report-footer-note').className).toMatch(/text-\[13px\]/)
    expect(screen.getByTestId('report-footer-toggle').className).toMatch(/text-brand-600/)
    // The facts are a separate row AFTER the card, one matching card each — not inside it.
    expect(card.contains(meta)).toBe(false)
    expect(card.compareDocumentPosition(meta) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    const cards = screen.getAllByTestId('report-footer-fact')
    expect(cards).toHaveLength(5)
    expect(meta.querySelectorAll('dt')).toHaveLength(5)
    cards.forEach((c) => expect(c.className).toMatch(/rounded-xl.*border.*bg-surface-secondary|border.*bg-surface-secondary/))
    expect(screen.queryByText('منهجية التقرير وملاحظات البيانات')).toBeNull()
  })

  it('ends a saved report’s page and has no methodology section, even where a disclaimer is configured', () => {
    const data = {
      slides: [{ id: 's1', type: 'recommendations', title: 'Next', visible: true }],
      recommendations: [],
      totals: {},
      mode: 'snapshot',
      generated_at: '2026-10-16T10:30:00Z',
      period: { from: '2026-09-16', to: '2026-10-15' },
      currency: 'USD',
      timezone: 'Asia/Riyadh',
      objective: 'leads',
      disclaimer: { sections: { short: { ar: 'قصير', en: 'short' }, full: { ar: 'كامل', en: 'full' }, methodology: { ar: 'منهجية', en: 'm' } }, enabled: {}, locale_default: 'ar' },
    }
    render(<InteractiveReport data={data as never} meta={{ currency: 'USD', locale: 'ar', platforms: [], clientName: 'C', title: 'R' } as never} />)

    expect(screen.getByTestId('report-footer')).toBeInTheDocument()
    expect(screen.queryByText('منهجية التقرير وملاحظات البيانات')).toBeNull()
    expect(screen.queryByText('قصير')).toBeNull()
    expect(screen.queryByText('كامل')).toBeNull()
  })
})

/** The Owner removed the spend-notes paragraph from every report footer (2026-10-19): it must never come back. */
describe('the footer carries no spend notes', () => {
  it('prints neither the invoices sentence nor a data-sources line, whatever the report shows', () => {
    const { container } = render(<ReportFooter locale="ar" facts={{ currency: 'USD' }} />)

    expect(screen.queryByTestId('report-footer-spend-notes')).toBeNull()
    expect(container.textContent).not.toContain('الفواتير الرسمية')
    expect(container.textContent).not.toContain('مصادر البيانات')
  })
})
