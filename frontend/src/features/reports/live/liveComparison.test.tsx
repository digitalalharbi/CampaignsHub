import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { LiveKpiBoard } from './LiveKpis'
import { useLiveMetricReader } from './liveMetrics'
import type { LivePayload } from '../api'

/**
 * REPORT-COVERAGE-001 — the client's KPI board compares two windows only when both are whole.
 */
const clean = {
  spend_original: 0, spend_withheld_rows: 0, revenue_original: 0, revenue_withheld_rows: 0,
  money_original_currency: null, money_original_currencies: 0,
}
const base = {
  period: { from: '2026-09-11', to: '2026-10-10', days: 30 },
  currency: 'SAR',
  timeseries: [], campaigns: [], funnel: [], store_funnel: null, freshness: [], metrics: [],
  available: { providers: ['meta'], campaigns: [], earliest: '2026-09-11', latest: '2026-10-10' },
  applied: { from: '2026-09-11', to: '2026-10-10', providers: [], campaigns: [] },
  is_demo: false,
  totals: { spend: 45_898.35, revenue: 504_860, roas: 11, cpa: 32.76, impressions: 2_569_040, clicks: 48_181, conversions: 1_401, ...clean },
  platforms: [{ provider: 'meta', spend: 45_898.35, conversions: 1_401, ...clean }],
} as unknown as LivePayload

function Board({ payload, ar }: { payload: LivePayload; ar: boolean }) {
  const reader = useLiveMetricReader(payload.currency, ar)
  return <LiveKpiBoard payload={payload} reader={reader} ar={ar} keys={['spend', 'conversions', 'roas', 'cpa']} />
}
const board = (payload: LivePayload, locale: 'ar' | 'en') =>
  renderWithProviders(<Board payload={payload} ar={locale === 'ar'} />, { locale })

describe('the live KPI board and its previous period', () => {
  it('shows trend pills when the windows compare', () => {
    board({ ...base, deltas: { spend: 0.1781, conversions: 0.2046 }, comparison: { comparable: true, window: null, contributors: [], through: null } }, 'en')
    // The spend pill: +17.81 % rounds to «18%».
    expect(screen.getByText('18%')).toBeInTheDocument()
    expect(screen.queryByTestId('live-comparison-withheld')).not.toBeInTheDocument()
  })

  it('withholds the pills and names the date and the platform when this period stopped short', () => {
    board({ ...base, deltas: { spend: null, conversions: null }, comparison: { comparable: false, window: 'current', contributors: ['meta'], through: '2026-09-27' } }, 'ar')
    const line = screen.getByTestId('live-comparison-withheld')
    expect(line.textContent).toContain('هذه الفترة مغطاة حتى 2026-09-27 فقط')
    expect(line.textContent).toContain('ميتا')
    expect(screen.queryByText('18%')).not.toBeInTheDocument()
  })

  it('says so in English when the PREVIOUS period is the incomplete one', () => {
    board({ ...base, deltas: {}, comparison: { comparable: false, window: 'previous', contributors: ['meta'], through: null } }, 'en')
    expect(screen.getByTestId('live-comparison-withheld').textContent).toContain('The previous period is incomplete (Meta)')
  })
})
