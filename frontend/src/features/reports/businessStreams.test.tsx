import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { BusinessStreamsSection } from './BusinessStreamsSection'
import { withStreamsSlide } from './reportSections'

/** REPORT-SECTION-STREAMS-001 — the operator's streams, in their words, only where the section is on. */
describe('business streams', () => {
  const streams = [
    { key: 's1', label: 'المبيعات عبر الإنترنت', figures: { spend: 300, conversions: 10, spend_state: 'complete_converted' }, share_of_spend: 0.75 },
  ]

  it('draws each stream under the operator’s own label with its share of spend', () => {
    renderWithProviders(<BusinessStreamsSection streams={streams} currency="SAR" ar />, { locale: 'ar' })

    expect(screen.getByTestId('business-stream-s1')).toHaveTextContent('المبيعات عبر الإنترنت')
    expect(screen.getByTestId('business-stream-s1')).toHaveTextContent('75%')
    expect(document.body.textContent).not.toMatch(/blended|مخلوط|مباشر/i)
  })

  it('calls the streams’ sum the total only when every in-scope account is mapped', () => {
    const { unmount } = renderWithProviders(<BusinessStreamsSection streams={streams} coverTotal currency="SAR" ar />, { locale: 'ar' })
    expect(screen.getByTestId('business-streams-coverage')).toHaveTextContent('مجموع المسارات يساوي إجمالي الإنفاق')
    unmount()

    renderWithProviders(<BusinessStreamsSection streams={streams} coverTotal={false} currency="SAR" ar />, { locale: 'ar' })
    expect(screen.getByTestId('business-streams-coverage')).toHaveTextContent('لا تغطي المسارات كل الحسابات')
    expect(screen.getByTestId('business-streams-coverage')).not.toHaveTextContent('يساوي إجمالي')
  })

  it('draws nothing without streams', () => {
    const { container } = renderWithProviders(<BusinessStreamsSection streams={[]} currency="SAR" ar />, { locale: 'ar' })
    expect(container.querySelector('[data-testid="business-streams"]')).toBeNull()
  })

  it('the deck gains a streams page only when the section is visible and streams exist', () => {
    const base = () => [{ id: 'cover', type: 'cover', order: 1, visible: true }]

    expect(withStreamsSlide(base(), { report_sections: ['kpis', 'advanced_segmentation'], business_streams: streams }).map((s) => s.type)).toEqual(['cover', '__streams'])
    expect(withStreamsSlide(base(), { report_sections: ['kpis'], business_streams: streams }).map((s) => s.type)).toEqual(['cover'])
    expect(withStreamsSlide(base(), { report_sections: ['advanced_segmentation'], business_streams: [] }).map((s) => s.type)).toEqual(['cover'])
  })
})
