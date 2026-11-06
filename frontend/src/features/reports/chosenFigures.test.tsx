import { beforeAll, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { carries } from './chosenFigures'
import { LiveSharedReport } from './LiveSharedReport'
import { PrintDocument } from './PrintDocument'
import { renderWithProviders } from '@/test/utils'

vi.mock('./api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('./api')>()),
  fetchLiveShared: vi.fn(),
}))

import { fetchLiveShared } from './api'

/**
 * REPORT-FILTERS-FUNCTIONAL-001 (Owner 2026-11-05) — a KPI the link did not choose arrives with no key at all, and the page draws no
 * block of it: no spend split over nothing, no «0 active platforms», no order by a figure it does not hold, no column of dashes.
 * A key that is there and null is a different fact (not reported, or withheld) and keeps its own wording.
 */
beforeAll(() => {
  if (!('fonts' in document)) Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
})

/** What the server sends a live link whose list is clicks and impressions: the keys of everything else are gone. */
const CHOSEN = {
  period: { from: '2026-10-01', to: '2026-10-31', days: 31 },
  currency: 'SAR',
  totals: { impressions: 146000, clicks: 1497 },
  deltas: {},
  timeseries: [{ date: '2026-10-10', clicks: 1330 }, { date: '2026-10-20', clicks: 167 }],
  platforms: [{ provider: 'meta', impressions: 89000, clicks: 914 }, { provider: 'snapchat', impressions: 57000, clicks: 583 }],
  funnel: [],
  store_funnel: null,
  freshness: [],
  available: { providers: ['meta', 'snapchat'], campaigns: [], earliest: '2026-10-01', latest: '2026-10-31' },
  metrics: ['clicks', 'impressions'],
  columns: { platforms: ['impressions', 'clicks'], days: ['impressions', 'clicks'], content: ['platform', 'impressions', 'clicks'] },
  ads_roster: [{ name: 'QCREATIVE', provider: 'meta', metrics: { impressions: 1200, clicks: 20 } }],
  applied: { from: '2026-10-01', to: '2026-10-31', providers: [], campaigns: [] },
  is_demo: false,
}

describe('an answer that carries only the chosen figures', () => {
  it('tells an absent key from a null one', () => {
    expect(carries({ spend: null }, 'spend')).toBe(true)
    expect(carries({ clicks: 1 }, 'spend')).toBe(false)
    expect(carries(undefined, 'spend')).toBe(true)
  })

  it('draws no spend block, counts the active platforms by what it carries, and offers no order it cannot honour', async () => {
    vi.mocked(fetchLiveShared).mockResolvedValue({ status: 200, envelope: { data: CHOSEN } } as never)
    renderWithProviders(<LiveSharedReport token="tok" currency="SAR" form="detailed" />, { route: '/r/tok?view=dashboard', locale: 'en' })
    await screen.findByTestId('live-report')

    expect(screen.queryByText('Spend by platform')).not.toBeInTheDocument()
    expect(screen.queryByText(/Spend line hidden/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Spend share unavailable/)).not.toBeInTheDocument()
    const counts = within(screen.getByTestId('live-scope-counts'))
    expect(counts.getByText('Active platforms').parentElement?.textContent).toContain('2')
  })

  it('prints no spend column for ads that carry no spend', () => {
    render(<PrintDocument data={{ period: { from: '2026-10-01', to: '2026-10-31' }, kpis: { clicks: 1497 }, summary: [], platforms: [], recommendations: [], outline: [], timeseries: [], report_sections: ['content_performance'], ads: [{ name: 'QCREATIVE', provider: 'meta', clicks: 20 }] } as never} currency="SAR" reportName="R" clientName="C" />)
    const table = document.querySelector('.doc-ads')
    expect(table).not.toBeNull()
    expect([...(table as Element).querySelectorAll('th')].map((h) => h.textContent)).toEqual(['Preview', 'Ad', 'Platform'])
  })
})
