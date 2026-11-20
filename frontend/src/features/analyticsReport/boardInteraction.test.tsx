import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, renderHook, screen, waitFor } from '@testing-library/react'
import { useLocation } from 'react-router-dom'
import { renderWithProviders, signInWith, signOut } from '@/test/utils'
import { REPORT_FIXTURE as P } from '@/features/reportLogic/testFixture'
import { useLiveMetricReader } from '@/features/reports/live/liveMetrics'
import type { LivePayload } from '@/features/reports/api'
import { BoardPlatformRanking, BoardPlatformSummary } from './ReportBoard'
import { CompareResult } from './CompareBoard'
import { baselineRange } from '@/features/reportLogic'
import { ReportFromViewButton } from './ReportFromView'

vi.mock('@/lib/api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('@/lib/api/client')>()), getData: vi.fn() }))
vi.mock('@/features/reports/api', async (importOriginal) => ({ ...(await importOriginal<typeof import('@/features/reports/api')>()), createLiveLink: vi.fn(), liveBuilderOptions: vi.fn() }))

import { getData } from '@/lib/api/client'
import { createLiveLink, liveBuilderOptions } from '@/features/reports/api'

const range = { from: '2026-11-01', to: '2026-11-07' }
const payload = { ...P, metrics: [], results: { ...P.results, configured: true, total: 40, by_platform: { meta: { results: 28, metric: 'purchases' }, snapchat: { results: 12, metric: 'purchases' } } } } as unknown as LivePayload

function Probe() {
  const l = useLocation()

  return <output data-testid="url">{l.search}</output>
}

afterEach(() => { cleanup(); vi.clearAllMocks(); signOut() })

describe('a click on a platform filters the whole page', () => {
  it('writes the shared URL filter from a card, and clears it on the same card', async () => {
    vi.mocked(getData).mockResolvedValue(payload as never)
    renderWithProviders(<><BoardPlatformSummary projectId="p1" range={range} /><Probe /></>)
    fireEvent.click(await screen.findByTestId('board-platform-card-meta'))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?provider=meta'))
    expect(screen.getByTestId('board-platform-card-meta')).toHaveAttribute('aria-selected', 'true')

    fireEvent.click(screen.getByTestId('board-platform-card-meta'))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe(''))
  })

  it('narrows from the donut legend and the ranking alike, keeping every platform on screen', async () => {
    vi.mocked(getData).mockResolvedValue(payload as never)
    renderWithProviders(<><BoardPlatformSummary projectId="p1" range={range} /><BoardPlatformRanking projectId="p1" range={range} /><Probe /></>)
    fireEvent.click(await screen.findByTestId('board-split-snapchat'))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?provider=snapchat'))
    // The filtered request names the platform; the cards still list every platform, so another can be picked.
    expect(vi.mocked(getData).mock.calls.some(([u]) => decodeURIComponent(String(u)).includes('providers[]=snapchat'))).toBe(true)
    expect(screen.getByTestId('board-platform-card-meta')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('board-rank-meta'))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?provider=meta'))
  })
})

describe('the ranking sorts', () => {
  it('re-orders on a column and reverses on a second press', async () => {
    vi.mocked(getData).mockResolvedValue(payload as never)
    renderWithProviders(<BoardPlatformRanking projectId="p1" range={range} />)
    const order = () => Array.from(screen.getByTestId('board-rank-list').querySelectorAll('[data-testid^="board-rank-"][aria-pressed]')).filter((e) => /-(meta|snapchat|tiktok)$/.test(e.getAttribute('data-testid') ?? '')).map((e) => e.getAttribute('data-testid')!.replace('board-rank-', ''))
    await screen.findByTestId('board-rank-list')
    expect(order()).toEqual(['meta', 'snapchat'])

    fireEvent.click(screen.getByTestId('board-rank-sort-impressions'))
    expect(screen.getByTestId('board-rank-list')).toHaveAttribute('data-sort', 'impressions')
    expect(order()).toEqual(['meta', 'snapchat'])
    fireEvent.click(screen.getByTestId('board-rank-sort-impressions'))
    expect(screen.getByTestId('board-rank-list')).toHaveAttribute('data-dir', 'asc')
    expect(order()).toEqual(['snapchat', 'meta'])
  })

  it('opens a platform\'s figures in a popup', async () => {
    vi.mocked(getData).mockResolvedValue(payload as never)
    renderWithProviders(<BoardPlatformRanking projectId="p1" range={range} />)
    fireEvent.click(await screen.findByTestId('board-rank-detail-meta'))
    expect(await screen.findByTestId('board-platform-detail')).toBeInTheDocument()
    expect(screen.getByTestId('board-platform-detail-clicks')).toHaveTextContent('400')
  })
})

describe('«مقارنة» — real numbers, never an estimate', () => {
  const reader = () => renderHook(() => useLiveMetricReader('SAR', true)).result.current
  const half = { ...payload, totals: { ...payload.totals, spend: 800, clicks: 275, revenue: 4000 } } as LivePayload

  it('shows the arrow and the percentage for two real sides', () => {
    renderWithProviders(<CompareResult ar reader={reader()} a={{ label: 'A', payload }} b={{ label: 'B', payload: half }} metric={null} onMetric={() => {}} currency="SAR" />, { locale: 'ar' })
    expect(screen.getByTestId('compare-delta-spend')).toHaveTextContent('+100.0%')
    expect(screen.getByTestId('compare-kpi-spend')).toHaveAttribute('data-direction', 'up')
    expect(screen.getByTestId('compare-overlay')).toBeInTheDocument()
  })

  it('says «غير متاح» for a side with nothing, and draws no delta or line for it', () => {
    renderWithProviders(<CompareResult ar reader={reader()} a={{ label: 'A', payload }} b={{ label: 'B', payload: null }} metric={null} onMetric={() => {}} currency="SAR" />, { locale: 'ar' })
    expect(screen.getByTestId('compare-b-spend')).toHaveTextContent('غير متاح')
    expect(screen.getByTestId('compare-delta-spend')).toHaveTextContent('غير متاح')
    expect(screen.getByTestId('compare-kpi-spend')).toHaveAttribute('data-direction', 'na')
    expect(screen.getByTestId('compare-overlay-unavailable')).toHaveTextContent('غير متاح')
    expect(screen.queryByTestId('compare-overlay')).toBeNull()
  })

  it('says it plainly when neither side has anything', () => {
    renderWithProviders(<CompareResult ar={false} reader={reader()} a={{ label: 'A', payload: null }} b={{ label: 'B', payload: null }} metric={null} onMetric={() => {}} currency="SAR" />)
    expect(screen.getByTestId('compare-unavailable')).toHaveTextContent('Not available')
  })

  it('lays the baseline over the same length of time', () => {
    expect(baselineRange(range, 'previous')).toEqual({ from: '2026-10-25', to: '2026-10-31' })
    expect(baselineRange(range, 'month')).toEqual({ from: '2026-10-01', to: '2026-10-07' })
    expect(baselineRange(range, 'year')).toEqual({ from: '2025-11-01', to: '2025-11-07' })
  })
})

describe('«إنشاء تقرير من هذا العرض»', () => {
  const options = {
    campaigns: [
      { id: 'c1', name: 'One', status: 'active', last_active_on: null, platforms: ['meta'], accounts: [{ id: 'acc-meta', name: 'M', provider: 'meta' }] },
      { id: 'c2', name: 'Two', status: 'active', last_active_on: null, platforms: ['snapchat'], accounts: [{ id: 'acc-snap', name: 'S', provider: 'snapchat' }] },
    ],
    providers: ['meta', 'snapchat'],
    metrics: [],
    ad_accounts: [{ id: 'acc-meta', name: 'M', provider: 'meta' }, { id: 'acc-snap', name: 'S', provider: 'snapchat' }],
  }

  it('is not offered without the share permission', () => {
    signInWith(['analytics.view'])
    renderWithProviders(<ReportFromViewButton projectId="p1" range={range} />)
    expect(screen.queryByTestId('report-from-view')).toBeNull()
  })

  it('creates the report over the view\'s own platform and period, and shows the /r/ link', async () => {
    signInWith(['reports.share'])
    vi.mocked(liveBuilderOptions).mockResolvedValue(options as never)
    vi.mocked(createLiveLink).mockResolvedValue({ report_id: 'r', share_id: 's', url: 'https://campaignshub.io/r/abc', token: 'abc' } as never)
    renderWithProviders(<ReportFromViewButton projectId="p1" range={range} />, { route: '/?provider=meta' })
    fireEvent.click(screen.getByTestId('report-from-view'))

    expect(await screen.findByTestId('report-from-view-url')).toHaveValue('https://campaignshub.io/r/abc')
    const body = vi.mocked(createLiveLink).mock.calls[0][1] as Record<string, unknown>
    expect(vi.mocked(createLiveLink).mock.calls[0][0]).toBe('p1')
    expect(body).toMatchObject({ account_ids: ['acc-meta'], providers: ['meta'], from: '2026-11-01', to: '2026-11-07', period_kind: 'custom', period_mode: 'fixed' })
    expect(body).not.toHaveProperty('accounts')
    expect(body).not.toHaveProperty('hide_spend')
  })

  it('narrows to the chosen campaign inside the account that ran it', async () => {
    signInWith(['reports.share'])
    vi.mocked(liveBuilderOptions).mockResolvedValue(options as never)
    vi.mocked(createLiveLink).mockResolvedValue({ report_id: 'r', share_id: 's', url: 'https://campaignshub.io/r/xyz', token: 'xyz' } as never)
    renderWithProviders(<ReportFromViewButton projectId="p1" range={range} />, { route: '/?campaign=c2' })
    fireEvent.click(screen.getByTestId('report-from-view'))
    await screen.findByTestId('report-from-view-url')
    const body = vi.mocked(createLiveLink).mock.calls[0][1] as Record<string, unknown>
    expect(body).toMatchObject({ account_ids: ['acc-snap'], accounts: { 'acc-snap': { campaign_ids: ['c2'] } }, providers: ['snapchat'] })
  })

  it('says so when the choice has no ad account, and creates nothing', async () => {
    signInWith(['reports.share'])
    vi.mocked(liveBuilderOptions).mockResolvedValue({ ...options, ad_accounts: [] } as never)
    renderWithProviders(<ReportFromViewButton projectId="p1" range={range} />, { locale: 'ar' })
    fireEvent.click(screen.getByTestId('report-from-view'))
    expect(await screen.findByTestId('report-from-view-error')).toHaveTextContent('لا حسابات إعلانية')
    expect(createLiveLink).not.toHaveBeenCalled()
  })
})
