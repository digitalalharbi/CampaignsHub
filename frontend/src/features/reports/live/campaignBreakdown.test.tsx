import { describe, expect, it } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { LiveCampaignBreakdown } from './LiveCampaignBreakdown'
import { LiveKpiBoard } from './LiveKpis'
import { useLiveMetricReader } from './liveMetrics'
import type { LivePayload } from '../api'

/** NASEL-CAMPAIGN-BREAKDOWN-001 / NASEL-KPI-ORDER-001 — the Owner's four KPIs in order, «غير متاح» kept, every objective listed. */
const payload = {
  period: { from: '2026-10-01', to: '2026-10-27', days: 27 },
  currency: 'USD',
  totals: { spend: 16.34, impressions: 52000, clicks: 1418, leads: null },
  deltas: {},
  metrics: ['spend', 'impressions', 'clicks', 'native_leads'],
  kpi_labels: { native_leads: 'العملاء (نموذج المنصة)' },
  timeseries: [],
  sections: { kpis: true },
  campaigns: [],
  campaign_rows: [
    { name: 'Riwaq — LandingPage', platform: 'tiktok', status: 'active', objective: 'traffic', spend: 9.18, impressions: 40000, clicks: 1346, native_leads: null, landing_leads: 700 },
    { name: 'Riwaq — LeadForm', platform: 'tiktok', status: 'paused', objective: 'leads', spend: 7.16, impressions: 12000, clicks: 72, native_leads: 1, landing_leads: null },
    { name: 'Riwaq — New flight', platform: 'snapchat', status: 'active', objective: 'awareness', data_state: 'no_data', spend: null, impressions: null, clicks: null, native_leads: null, landing_leads: null },
  ],
} as unknown as LivePayload

function Board() {
  const reader = useLiveMetricReader('USD', true)

  return <LiveKpiBoard payload={payload} reader={reader} ar keys={reader.keys(payload)} />
}

describe('NASEL client link', () => {
  it('draws the four chosen KPIs in order, and an unconfigured native-lead count reads «غير متاح» instead of disappearing', () => {
    render(<Board />)
    const cards = Array.from(screen.getByTestId('live-kpis').children).map((c) => c.textContent ?? '')

    expect(cards).toHaveLength(4)
    expect(cards[0]).toMatch(/الإنفاق/)
    expect(cards[1]).toMatch(/الظهور/)
    expect(cards[2]).toMatch(/النقرات/)
    expect(cards[3]).toMatch(/العملاء \(نموذج المنصة\)/)
    expect(cards[3]).toMatch(/غير متاح/)
  })

  it('lists every campaign whatever its objective, with the link\'s KPI names, and never a zero for an unreported lead', () => {
    render(<LiveCampaignBreakdown payload={payload} currency="USD" ar />)
    const table = screen.getByTestId('live-campaign-breakdown')
    const names = within(table).getAllByTestId('live-campaign-row')
    // MetricTable sorts nothing until asked, so the rows keep the server's order; each row is read whole.
    const rows = names.map((n) => n.closest('tr') as HTMLElement)

    expect(rows).toHaveLength(3)
    expect(table).toHaveTextContent('العملاء (نموذج المنصة)')
    // Awaiting metrics is said, not left as a blank row or a row of zeros.
    expect(names[2]).toHaveAttribute('data-state', 'no_data')
    expect(within(rows[2]).getByTestId('live-campaign-state')).toHaveTextContent('بانتظار البيانات')
    expect(rows[2]).not.toHaveTextContent(/\b0\b/)
    expect(rows[0]).toHaveTextContent('Riwaq — LandingPage')
    expect(rows[0]).toHaveTextContent('غير متاح')
    expect(rows[0]).toHaveTextContent(/9\.18/)
    expect(rows[0]).toHaveTextContent(/USD/)
    expect(rows[1]).toHaveTextContent('Riwaq — LeadForm')
  })

  it('draws nothing when the link did not switch the breakdown on', () => {
    const { container } = render(<LiveCampaignBreakdown payload={{ ...payload, campaign_rows: [] } as LivePayload} currency="USD" ar />)

    expect(container).toBeEmptyDOMElement()
  })
})
