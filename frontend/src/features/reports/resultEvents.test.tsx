import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import { gateResultKeys } from './live/liveMetrics'
import { reportMetrics } from './reportMetrics'
import { ResultEventsPanel } from './ResultEventsPanel'
import type { LivePayload, ResultEventsMap } from './api'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchResultEvents: vi.fn(() => Promise.resolve({ result_events: {}, platforms: ['tiktok'], paths: ['all', 'lead_form', 'landing_page'], metrics: ['leads', 'conversions'] })),
  saveResultEvents: vi.fn(() => Promise.resolve({ result_events: {}, platforms: [], paths: [], metrics: [] })),
}))

import { saveResultEvents } from './api'

const live = (over: Partial<LivePayload>): LivePayload => over as LivePayload

/** RESULT-EVENTS-001 — Results exist only where configured; a platform's generic conversion is never a Result. */
describe('result cards are gated by configuration', () => {
  it('hides Results and Cost per result while nothing is configured, even when selected', () => {
    expect(gateResultKeys(['spend', 'results', 'cost_per_result'], live({ results: { configured: false, total: null, cost: null, by_platform: {}, by_path: {}, events: {} } })))
      .toEqual(['spend'])
  })

  it('shows them once a result event is configured', () => {
    const results = { configured: true, total: 3, cost: 4, by_platform: {}, by_path: {}, events: {} }
    expect(gateResultKeys(['spend', 'results', 'cost_per_result'], live({ results }))).toEqual(['spend', 'results', 'cost_per_result'])
  })

  it('does not default to raw conversions or cost per conversion when no KPIs were chosen and no purchases were reported', () => {
    expect(gateResultKeys(['spend', 'conversions', 'cpa'], live({ metrics: [], reported: { purchases: false } }))).toEqual(['spend'])
    expect(gateResultKeys(['spend', 'conversions', 'cpa'], live({ metrics: [], reported: { purchases: true } }))).toEqual(['spend', 'conversions', 'cpa'])
  })

  it('keeps an explicit choice of the raw keys on a link made before Results were configurable', () => {
    expect(gateResultKeys(['spend', 'conversions'], live({ metrics: ['spend', 'conversions'] }))).toEqual(['spend', 'conversions'])
  })
})

describe('verified lead cards', () => {
  const leads = { configured: true, total: 4, landing: 4, native: null, cost: 5, by_platform: {}, by_path: {}, events: {} }
  const results = { configured: false, total: null, cost: null, by_platform: {}, by_path: {}, events: {}, leads }

  it('offers the landing and native breakdown only where lead events are configured', () => {
    expect(gateResultKeys(['leads', 'landing_leads', 'native_leads'], live({ results }))).toEqual(['leads', 'landing_leads', 'native_leads'])
    expect(gateResultKeys(['leads', 'landing_leads', 'native_leads'], live({ results: { ...results, leads: { ...leads, configured: false } } }))).toEqual(['leads'])
  })

  it('reads Leads as the verified total, the sources on their own, and an unreported source as unavailable', () => {
    const cards = reportMetrics({ objective: 'leads', currency: 'SAR', kpis: { leads: 0 }, reported: { leads: false }, metric_set: ['leads', 'landing_leads', 'native_leads', 'cpl'], results }, false)
    expect(cards.map((c) => c.key)).toEqual(['leads', 'landing_leads', 'native_leads', 'cpl'])
    expect(cards[0].reading).toMatchObject({ kind: 'value' })
    expect(cards[1].reading).toMatchObject({ kind: 'value' })
    expect(cards[2].reading).not.toMatchObject({ kind: 'value' })
  })
})

describe('saved report cards', () => {
  const base = { objective: 'leads', currency: 'SAR', kpis: { spend: 20, impressions: 1000, clicks: 50, conversions: 700, leads: 0 }, reported: { leads: false, conversions: true }, metric_set: ['spend', 'results', 'cost_per_result', 'leads'] }

  it('draws no Results cards without a configured event, and never the 700 conversions', () => {
    const keys = reportMetrics({ ...base, results: { configured: false, total: null, cost: null } }, false).map((m) => m.key)
    expect(keys).toEqual(['spend', 'leads'])
  })

  it('draws the configured Result and its cost, in the order selected', () => {
    const cards = reportMetrics({ ...base, results: { configured: true, total: 5, cost: 4 } }, false)
    expect(cards.map((m) => m.key)).toEqual(['spend', 'results', 'cost_per_result', 'leads'])
    expect(cards.find((m) => m.key === 'results')?.reading).toMatchObject({ kind: 'value' })
  })
})

function Harness({ onValue }: { onValue: (v: ResultEventsMap) => void }) {
  const [value, setValue] = useState<ResultEventsMap>({})
  onValue(value)

  return <ResultEventsPanel projectId="p1" providers={['tiktok']} value={value} onChange={setValue} />
}

describe('result events panel', () => {
  it('starts on none, maps a path to a reported metric, and saves the project default only on request', async () => {
    let latest: ResultEventsMap = {}
    renderWithProviders(<Harness onValue={(v) => { latest = v }} />, { locale: 'en' })

    expect(screen.getByTestId('result-event-tiktok-lead_form')).toHaveValue('')

    await waitFor(() => expect(screen.getAllByRole('option', { name: 'Reported leads' }).length).toBeGreaterThan(0))
    fireEvent.change(screen.getByTestId('result-event-tiktok-lead_form'), { target: { value: 'leads' } })
    expect(latest).toEqual({ tiktok: { lead_form: 'leads' } })
    expect(saveResultEvents).not.toHaveBeenCalled()

    fireEvent.click(screen.getByTestId('result-events-save'))
    await waitFor(() => expect(saveResultEvents).toHaveBeenCalledWith('p1', { tiktok: { lead_form: 'leads' } }))

    fireEvent.change(screen.getByTestId('result-event-tiktok-lead_form'), { target: { value: '' } })
    expect(latest).toEqual({})
  })
})
