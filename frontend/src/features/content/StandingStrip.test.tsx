import { describe, expect, it } from 'vitest'
import { fireEvent, screen, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ObjectiveCompare, StandingStrip } from './StandingStrip'
import { whyById, type StandingItem } from './standing'

const item = (over: Partial<StandingItem> = {}): StandingItem => ({
  id: 'a', objective: 'sales', provider: 'meta', family: 'sales', metric: 'roas', metric_label_ar: 'العائد', metric_label_en: 'ROAS', higher_is_better: true,
  rank: 1, of: 8, class: 'best',
  result: { key: 'conversions', value: 40, label_ar: 'الطلبات', label_en: 'Orders', missing_ar: null, missing_en: null },
  ...over,
})

const why = whyById([
  { id: 'a', name: 'A', provider: 'meta', objective: 'sales', metrics: { impressions: 10000, clicks: 300, spend: 100, frequency: 2.4 }, fatigue: { status: 'watch', signals: [{ metric: 'ctr', direction: 'down', change: -0.2 }] } },
  { id: 'b', name: 'B', provider: 'meta', objective: 'sales', metrics: { impressions: 10000, clicks: 100, spend: 100, frequency: 1.1 }, fatigue: { status: 'stable', signals: [] } },
  { id: 'c', name: 'C', provider: 'meta', objective: 'sales', metrics: { impressions: 10000, clicks: 200, spend: 200, frequency: 1.5 }, fatigue: { status: 'stable', signals: [] } },
])

describe('the card’s standing strip', () => {
  it('shows the class, the position and the objective’s own result', () => {
    renderWithProviders(<StandingStrip item={item()} why={why.get('a')} name="Hero" ar />, { locale: 'ar' })

    expect(screen.getByTestId('standing-class')).toHaveTextContent('الأفضل أداءً')
    expect(screen.getByTestId('standing-rank')).toHaveTextContent('1 من 8')
    expect(screen.getByTestId('standing-indicator')).toHaveTextContent('الطلبات')
    expect(screen.getByTestId('standing-indicator')).toHaveTextContent('40')
  })

  it('draws «فرصة للتحسين» for the weak end, and no class for content in neither list', () => {
    const { rerender } = renderWithProviders(<StandingStrip item={item({ class: 'improve', rank: 8 })} why={undefined} name="Hero" ar />, { locale: 'ar' })
    expect(screen.getByTestId('standing-class')).toHaveTextContent('فرصة للتحسين')

    rerender(<StandingStrip item={item({ class: null, rank: 4 })} why={undefined} name="Hero" ar />)
    expect(screen.queryByTestId('standing-class')).toBeNull()
    expect(screen.getByTestId('standing-rank')).toBeInTheDocument()
  })

  it('says «غير مُقاس» and shows no rank when the objective result was not reported', () => {
    renderWithProviders(
      <StandingStrip item={item({ class: null, rank: null, of: null, result: { key: 'conversions', value: null, label_ar: 'الطلبات', label_en: 'Orders', missing_ar: 'بدون بيانات', missing_en: 'No data' } })} why={undefined} name="Hero" ar />,
      { locale: 'ar' },
    )

    expect(screen.getByTestId('standing-indicator')).toHaveAttribute('data-measured', 'false')
    expect(screen.getByTestId('standing-indicator')).toHaveTextContent('غير مُقاس')
    expect(screen.queryByTestId('standing-rank')).toBeNull()
  })

  it('draws nothing until the server has answered', () => {
    renderWithProviders(<StandingStrip item={undefined} why={undefined} name="Hero" ar />, { locale: 'ar' })

    expect(screen.queryByTestId('standing-strip')).toBeNull()
  })

  it('opens «ليش نجح» with the gap, the share and the fatigue read-out', () => {
    renderWithProviders(<StandingStrip item={item()} why={why.get('a')} name="Hero" ar />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('standing-why-open'))

    const popup = screen.getByTestId('standing-why')
    expect(within(popup).getByTestId('standing-why-sentence')).toHaveTextContent('ليش نجح: نسبة الضغط أعلى من المتوسط بـ 50%.')
    expect(popup).toHaveTextContent('+50%')
    expect(popup).toHaveTextContent('25%')
    expect(popup).toHaveTextContent('2.4')
    expect(popup).toHaveTextContent('-20%')
    expect(popup).toHaveTextContent('يحتاج مراقبة')
  })

  it('says why there is no reading when the group is too small', () => {
    renderWithProviders(<StandingStrip item={item()} why={undefined} name="Hero" ar={false} />, { locale: 'en' })
    fireEvent.click(screen.getByTestId('standing-why-open'))

    expect(screen.getByTestId('standing-why-sentence')).toHaveTextContent('Not measured')
  })
})

describe('the compare popup’s shared-objective block', () => {
  const names = { a: 'First', b: 'Second', c: 'Third' }

  it('shows each item’s own result with a delta arrow and percent against the reference', () => {
    renderWithProviders(
      <ObjectiveCompare items={[item({ id: 'a' }), item({ id: 'b', result: { ...item().result, value: 60 } }), item({ id: 'c', result: { ...item().result, value: 30 } })]} names={names} ar />,
      { locale: 'ar' },
    )

    expect(screen.getByTestId('objective-compare')).toHaveAttribute('data-comparable', 'true')
    const up = within(screen.getByTestId('objective-compare-b')).getByTestId('objective-compare-delta')
    expect(up).toHaveAttribute('data-direction', 'up')
    expect(up).toHaveTextContent('+50%')
    const down = within(screen.getByTestId('objective-compare-c')).getByTestId('objective-compare-delta')
    expect(down).toHaveAttribute('data-direction', 'down')
    expect(down).toHaveTextContent('-25%')
    expect(within(screen.getByTestId('objective-compare-a')).queryByTestId('objective-compare-delta')).toBeNull()
  })

  it('shows the objective mismatch honestly instead of ranking across objectives', () => {
    renderWithProviders(
      <ObjectiveCompare items={[item({ id: 'a' }), item({ id: 'b', family: 'leads', result: { key: 'leads', value: 9, label_ar: 'المهتمين', label_en: 'Leads', missing_ar: null, missing_en: null } })]} names={names} ar />,
      { locale: 'ar' },
    )

    expect(screen.getByTestId('objective-compare')).toHaveAttribute('data-comparable', 'false')
    expect(screen.getByTestId('objective-compare-reason')).toHaveAttribute('data-reason', 'objective_mismatch')
    expect(screen.getByTestId('objective-compare-reason')).toHaveTextContent('أهداف مختلفة')
    expect(screen.queryAllByTestId('objective-compare-delta')).toHaveLength(0)
    // Each item still states its own result.
    expect(screen.getByTestId('objective-compare-b')).toHaveTextContent('المهتمين')
  })

  it('renders nothing for fewer than two items', () => {
    renderWithProviders(<ObjectiveCompare items={[item()]} names={names} ar />, { locale: 'ar' })

    expect(screen.queryByTestId('objective-compare')).toBeNull()
  })
})
