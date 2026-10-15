import { describe, expect, it } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { LiveKpiBoard } from './LiveKpis'
import { cardHasFigure, metricLabel, useLiveMetricReader } from './liveMetrics'
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

  it('names a messaging result «الرسائل» and never the generic «النتائج»', () => {
    const payload = { ...base, result_kind: { kind: 'messaging', result: { ar: 'الرسائل', en: 'Messages' }, cost: { ar: 'تكلفة الرسالة', en: 'Cost per message' } } } as LivePayload
    render(<Board payload={payload} />)
    const board = screen.getByTestId('live-kpis')

    expect(within(board).getByText('الرسائل')).toBeInTheDocument()
    expect(within(board).queryByText('النتائج')).not.toBeInTheDocument()
  })

  it('names the cost per message when the cost is stated', () => {
    const meta = { ar: 'تكلفة النتيجة', en: 'Cost per result' } as never
    const payload = { ...base, result_kind: { kind: 'messaging', result: { ar: 'الرسائل', en: 'Messages' }, cost: { ar: 'تكلفة الرسالة', en: 'Cost per message' } } } as LivePayload

    expect(metricLabel(meta, 'cpa', payload, true)).toBe('تكلفة الرسالة')
    expect(metricLabel(meta, 'cpa', base, true)).toBe('تكلفة النتيجة')
  })

  it('a dash is not a figure, and a real zero is', () => {
    expect(cardHasFigure('spend', '—', base)).toBe(false)
    expect(cardHasFigure('conversions', '0', base)).toBe(true)
  })
})
