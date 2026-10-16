import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { InteractiveReport } from './InteractiveReport'
import { REPORT_NOTE, ReportFooter, reportFooterItems } from './reportFooter'

/**
 * REPORT-FOOTER-001 — one subtle footer, the same on every surface; never a section.
 */
describe('the report footer', () => {
  const facts = { updatedAt: '2026-10-16T10:30:00Z', period: { from: '2026-09-16', to: '2026-10-15' }, currency: 'USD', timezone: 'Asia/Riyadh', objective: 'leads' }

  it('states the Owner’s exact note and the five facts under it, in order', () => {
    render(<ReportFooter locale="ar" facts={facts} />)

    expect(screen.getByTestId('report-footer-note')).toHaveTextContent(REPORT_NOTE.ar)
    expect(REPORT_NOTE.ar).toBe('ملاحظة: تعتمد قراءة النتائج على الأداء والبيانات المتاحة خلال فترة التقرير، وقد تختلف المؤشرات باختلاف مصادر القياس وآليات الإسناد والعوامل المؤثرة على الحملة.')
    const meta = screen.getByTestId('report-footer-meta').textContent ?? ''
    const order = ['آخر تحديث', 'الفترة', 'العملة', 'المنطقة الزمنية', 'هدف الحملة'].map((l) => meta.indexOf(l))
    expect(order.every((i) => i >= 0)).toBe(true)
    expect([...order].sort((a, b) => a - b)).toEqual(order)
  })

  it('leaves out a fact that has no value instead of printing a dash', () => {
    const labels = reportFooterItems({ ...facts, objective: null, timezone: null }, 'ar').map(([k]) => k)

    expect(labels).toEqual(['آخر تحديث', 'الفترة', 'العملة'])
  })

  it('is outside the report body: no heading, no card, and not a section', () => {
    const { container } = render(<ReportFooter locale="en" facts={facts} />)
    const footer = screen.getByTestId('report-footer')

    expect(container.querySelector('h1,h2,h3,h4')).toBeNull()
    expect(footer.className).not.toMatch(/\brounded-|\bbg-surface|\bshadow-/)
    expect(footer.className).toMatch(/text-\[11px\]/)
    expect(footer.className).toMatch(/text-text-muted/)
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
