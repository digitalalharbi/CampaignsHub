import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { ExecutiveSummary } from '@/features/reports/live/ExecutiveSummary'
import { LiveKpiBoard } from '@/features/reports/live/LiveKpis'
import { useLiveMetricReader } from '@/features/reports/live/liveMetrics'
import { boardKpis } from '@/features/reportLogic'
import { REPORT_FIXTURE as P } from '@/features/reportLogic/testFixture'
import type { LivePayload } from '@/features/reports/api'
import { BoardPlatformRanking, BoardPlatformSummary } from './ReportBoard'

vi.mock('@/lib/api/client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/lib/api/client')>()),
  getData: vi.fn(),
}))

import { getData } from '@/lib/api/client'

/**
 * ANALYTICS-REPORT-LOGIC-001 — «أي فرق بين التقرير والتحليلات لنفس الفترة والمشروع يعتبر خطأ».
 *
 * ONE payload is drawn by the /r/ report's own components and by the analytics board's, and the figure each draws, per platform, is
 * compared as text: the platform cards, the sources split, the ranking, and the key indicators. The board reads the payload
 * through `analytics/report-view`, which runs the report's builder (asserted on the server in `AnalyticsReportViewTest`); here the
 * other half is held — that the same payload reads the same on both surfaces.
 */
const payload = { ...P, metrics: ['spend', 'clicks', 'conversions'], results: { ...P.results, configured: true, total: 40, by_platform: { meta: { results: 28, metric: 'purchases' }, snapchat: { results: 12, metric: 'purchases' } } } } as unknown as LivePayload

function Report({ ar }: { ar: boolean }) {
  const reader = useLiveMetricReader(payload.currency ?? '', ar)

  return <ExecutiveSummary payload={payload} reader={reader} currency="SAR" locale={ar ? 'ar' : 'en'} onOpenContent={() => {}} sectionOn={() => true} />
}

const texts = (root: HTMLElement, ids: string[]) => Object.fromEntries(ids.map((id) => [id, within(root).getByTestId(id).textContent?.replace(/\s+/g, ' ').trim()]))

describe.each([true, false])('report and board draw the same figures (ar=%s)', (ar) => {
  afterEach(() => { cleanup(); vi.clearAllMocks() })

  it('platform cards: every card says the same words and numbers', async () => {
    const view = renderWithProviders(<Report ar={ar} />, { locale: ar ? 'ar' : 'en' })
    const keys = ['all', 'meta', 'snapchat']
    const report = texts(view.container, keys.map((k) => `exec-platform-card-${k}`))
    cleanup()

    vi.mocked(getData).mockResolvedValue(payload as never)
    const board = renderWithProviders(<BoardPlatformSummary projectId="p1" range={{ from: '2026-11-01', to: '2026-11-07' }} />, { locale: ar ? 'ar' : 'en' })
    await screen.findByTestId('board-platform-card-all')
    const drawn = texts(board.container, keys.map((k) => `board-platform-card-${k}`))

    keys.forEach((k) => expect(drawn[`board-platform-card-${k}`], k).toBe(report[`exec-platform-card-${k}`]))
    expect(drawn['board-platform-card-all']).toContain('40')
  })

  it('sources split: each platform\'s share is the same percentage', async () => {
    const view = renderWithProviders(<Report ar={ar} />, { locale: ar ? 'ar' : 'en' })
    const report = texts(view.container, ['exec-split-meta', 'exec-split-snapchat'])
    cleanup()

    vi.mocked(getData).mockResolvedValue(payload as never)
    const board = renderWithProviders(<BoardPlatformSummary projectId="p1" range={{ from: '2026-11-01', to: '2026-11-07' }} />, { locale: ar ? 'ar' : 'en' })
    await screen.findByTestId('board-split-meta')
    const drawn = texts(board.container, ['board-split-meta', 'board-split-snapchat'])

    expect(drawn['board-split-meta']).toBe(report['exec-split-meta'])
    expect(drawn['board-split-snapchat']).toBe(report['exec-split-snapchat'])
    expect(drawn['board-split-meta']).toContain('70%')
  })

  it('ranking: the same platforms, the same order, the same result', async () => {
    const view = renderWithProviders(<Report ar={ar} />, { locale: ar ? 'ar' : 'en' })
    const rows = Array.from(view.container.querySelectorAll('[data-testid^="exec-platform-"][aria-pressed]')).filter((el) => /^exec-platform-(meta|snapchat|tiktok)$/.test(el.getAttribute('data-testid') ?? ''))
    const reportOrder = rows.map((el) => el.getAttribute('data-testid')!.replace('exec-platform-', ''))
    const reportMeta = within(rows[0].closest('tr') as HTMLElement).getByText('28')
    expect(reportMeta).toBeTruthy()
    cleanup()

    vi.mocked(getData).mockResolvedValue(payload as never)
    const board = renderWithProviders(<BoardPlatformRanking projectId="p1" range={{ from: '2026-11-01', to: '2026-11-07' }} />, { locale: ar ? 'ar' : 'en' })
    await screen.findByTestId('board-rank-list')
    const boardOrder = Array.from(board.container.querySelectorAll('[data-testid^="board-rank-"][aria-pressed]')).filter((el) => /^board-rank-(meta|snapchat|tiktok)$/.test(el.getAttribute('data-testid') ?? '')).map((el) => el.getAttribute('data-testid')!.replace('board-rank-', ''))

    expect(boardOrder).toEqual(reportOrder)
    expect(within(screen.getByTestId('board-rank-meta')).getByText('28')).toBeTruthy()
    expect(within(screen.getByTestId('board-rank-snapchat')).getByText('12')).toBeTruthy()
  })

  it('key indicators: the board\'s figure is the report card\'s figure', () => {
    const { result } = renderHook(() => useLiveMetricReader('SAR', ar))
    const kpis = boardKpis(payload, result.current, ar)
    expect(kpis.length).toBeGreaterThanOrEqual(3)

    for (const k of kpis) {
      render(<LiveKpiBoard payload={{ ...payload, metrics: [k.key] }} reader={result.current} ar={ar} keys={[k.key]} />)
      const drawn = screen.getByTestId('live-kpis').querySelector('[dir="ltr"]')?.textContent?.trim()
      expect(drawn, k.key).toBe(k.value.trim())
      cleanup()
    }
  })
})

describe('the board is empty, not wrong, when the report is', () => {
  afterEach(() => { cleanup(); vi.clearAllMocks() })

  it('draws nothing for a project with no platform data', async () => {
    vi.mocked(getData).mockResolvedValue({ ...payload, platforms: [] } as never)
    renderWithProviders(<BoardPlatformSummary projectId="p1" range={{ from: '2026-11-01', to: '2026-11-07' }} />)
    await waitFor(() => expect(vi.mocked(getData)).toHaveBeenCalled())
    expect(screen.queryByTestId('board-platform-summary')).toBeNull()
  })
})
