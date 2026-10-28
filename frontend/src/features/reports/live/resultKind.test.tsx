import { describe, expect, it } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { LiveKpiBoard } from './LiveKpis'
import { cardHasFigure, metricLabel, useLiveMetricReader } from './liveMetrics'
import { LivePlatformComparison } from '../LiveDetailTables'
import type { LivePayload } from '../api'

/**
 * REPORT-RESULT-KIND-001 — a WhatsApp campaign's result is «Messages», a link that hides spend has
 * no spend card at all, and no card is a hole in the grid.
 */
const base = {
  period: { from: '2026-09-16', to: '2026-10-15', days: 30 },
  currency: 'USD',
  totals: { impressions: 132539, clicks: 1723, conversions: 211, leads: 211, spend: null, cpa: null },
  deltas: {},
  metrics: ['spend', 'impressions', 'clicks', 'conversions', 'cpa'],
  timeseries: [],
  sections: { kpis: true },
} as unknown as LivePayload

function Board({ payload }: { payload: LivePayload }) {
  const reader = useLiveMetricReader('USD', true)

  return <LiveKpiBoard payload={payload} reader={reader} ar keys={reader.keys(payload)} />
}

describe('live KPI board', () => {
  it('omits the spend and cost cards a link hides instead of drawing «—»', () => {
    render(<Board payload={base} />)
    const board = screen.getByTestId('live-kpis')

    expect(within(board).queryByText('الإنفاق')).not.toBeInTheDocument()
    expect(within(board).queryByText('تكلفة النتيجة')).not.toBeInTheDocument()
    expect(within(board).getByText('الظهور')).toBeInTheDocument()
    expect(board).not.toHaveTextContent('—')
  })

  it('keeps the result card «النتائج» for a messaging campaign — the type is context, not a name', () => {
    const payload = { ...base, result_kind: { kind: 'messaging', result: { ar: 'الرسائل', en: 'Messages' }, cost: { ar: 'تكلفة الرسالة', en: 'Cost per message' } } } as LivePayload
    render(<Board payload={payload} />)
    const board = screen.getByTestId('live-kpis')

    expect(within(board).getByText('النتائج')).toBeInTheDocument()
    expect(within(board).queryByText('الرسائل')).not.toBeInTheDocument()
  })

  it('names the cost per message when the cost is stated', () => {
    const meta = { ar: 'تكلفة النتيجة', en: 'Cost per result' } as never
    const payload = { ...base, result_kind: { kind: 'messaging', result: { ar: 'الرسائل', en: 'Messages' }, cost: { ar: 'تكلفة الرسالة', en: 'Cost per message' } } } as LivePayload

    expect(metricLabel(meta, 'cpa', payload, true)).toBe('تكلفة النتيجة')
    expect(metricLabel(meta, 'cpa', base, true)).toBe('تكلفة النتيجة')
  })

  // NASEL-KPI-ORDER-001 — a chosen set reads in the order chosen (spend, impressions, clicks, results, cost here).
  it('reads a messaging report in the operator\'s chosen order: impressions, clicks, then results', () => {
    const payload = {
      ...base,
      totals: { ...base.totals, spend: 100, cpa: 0.5 },
      result_kind: { kind: 'messaging', result: { ar: 'الرسائل', en: 'Messages' }, cost: { ar: 'تكلفة الرسالة', en: 'Cost per message' } },
    } as unknown as LivePayload
    render(<Board payload={payload} />)
    const labels = Array.from(document.body.querySelectorAll('span, dt')).map((n) => n.textContent)
    const at = (l: string) => labels.indexOf(l)

    expect(at('الظهور')).toBeLessThan(at('النقرات'))
    expect(at('النقرات')).toBeLessThan(at('النتائج'))
  })

  it('a dash is not a figure, and a real zero is', () => {
    expect(cardHasFigure('spend', '—', base)).toBe(false)
    expect(cardHasFigure('conversions', '0', base)).toBe(true)
  })

  it('draws no spend or cost column in the platform table of a link that hides spend', () => {
    const payload = {
      ...base,
      platforms: [{ provider: 'meta', spend: null, conversions: 213, impressions: 134000, clicks: 1740, ctr: 0.013 }],
      result_kind: { kind: 'messaging', result: { ar: 'الرسائل', en: 'Messages' }, cost: { ar: 'تكلفة الرسالة', en: 'Cost per message' } },
    } as unknown as LivePayload
    const { container } = render(<LivePlatformComparison payload={payload} currency="USD" locale="ar" />)
    const heads = Array.from(container.querySelectorAll('th')).map((n) => n.textContent ?? '')

    expect(heads.join('|')).not.toMatch(/الإنفاق|تكلفة/)
    expect(heads.join('|')).toMatch(/النتائج/)
    expect(heads.join('|')).not.toMatch(/الرسائل/)
  })

  const messaging = { kind: 'messaging', result: { ar: 'الرسائل', en: 'Messages' }, cost: { ar: 'تكلفة الرسالة', en: 'Cost per message' } }

  it('spend enabled and available leads the row: spend, impressions, results, cost per result', () => {
    const payload = {
      ...base,
      metrics: ['spend', 'impressions', 'conversions', 'cpa'],
      totals: { ...base.totals, spend: 2640.18, cpa: 4.56, leads: 579, conversions: 579 },
      result_kind: messaging,
    } as unknown as LivePayload
    render(<Board payload={payload} />)
    const cards = Array.from(screen.getByTestId('live-kpis').children).map((c) => c.textContent ?? '')

    expect(cards).toHaveLength(4)
    expect(cards[0]).toMatch(/الإنفاق/)
    expect(cards[1]).toMatch(/الظهور/)
    expect(cards[2]).toMatch(/النتائج/)
    expect(cards[2]).not.toMatch(/الرسائل/)
    expect(cards[3]).toMatch(/تكلفة النتيجة/)
    expect(document.body.textContent).not.toMatch(/الرسائل|العملاء المحتملون/)
  })

  it('spend not selected by the Owner is absent and nothing takes its slot', () => {
    const payload = {
      ...base,
      metrics: ['impressions', 'conversions'],
      totals: { ...base.totals, spend: 2640.18, cpa: 4.56 },
      result_kind: messaging,
    } as unknown as LivePayload
    render(<Board payload={payload} />)
    const cards = Array.from(screen.getByTestId('live-kpis').children).map((c) => c.textContent ?? '')

    expect(cards).toHaveLength(2)
    expect(cards.join('|')).not.toMatch(/الإنفاق/)
  })

  it('a chosen cost with no figure reads «غير متاح» in its own slot — never a zero, never another metric', () => {
    const payload = {
      ...base,
      metrics: ['spend', 'impressions', 'conversions', 'cpa'],
      totals: { ...base.totals, spend: 2640.18, cpa: null },
      result_kind: messaging,
    } as unknown as LivePayload
    render(<Board payload={payload} />)
    const cards = Array.from(screen.getByTestId('live-kpis').children).map((c) => c.textContent ?? '')

    expect(cards).toHaveLength(4)
    expect(cards[3]).toMatch(/تكلفة النتيجة/)
    expect(cards[3]).toMatch(/غير متاح/)
    expect(cards.join('|')).not.toMatch(/النقرات/)
  })
})
