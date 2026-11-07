import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { useState } from 'react'
import { fireEvent, render, screen, within } from '@testing-library/react'
import type { LivePayload } from '../api'
import { useLiveMetricReader } from './liveMetrics'
import { TablesView } from './LiveTables'
import { ContentView } from './LiveViews'

/*
 * `figuresFor` is what every content tile calls to state its three figures; counting its calls is
 * counting tile renders, which is what the memo on the tile exists to bound.
 */
vi.mock('../ReportAdsSection', async (importOriginal) => {
  const real = await importOriginal<typeof import('../ReportAdsSection')>()

  return { ...real, figuresFor: vi.fn(real.figuresFor) }
})

import { figuresFor } from '../ReportAdsSection'

/**
 * PERF-RENDER-001 — a roster of three hundred creatives draws without building a formatter per figure.
 *
 * `Intl.NumberFormat` is the cost in formatting a number: constructing one resolves a locale and
 * builds a pattern, and the roster was constructing one for every cell of every row on every render.
 * `numberFormatter` keeps one per (locale, options). This proves the count is bounded by the SHAPES
 * the table uses — a dozen at most — and does not move with the rows.
 */
const RealNumberFormat = Intl.NumberFormat
let constructions = 0

beforeAll(() => {
  Intl.NumberFormat = new Proxy(RealNumberFormat, {
    construct(target, args) {
      constructions += 1

      return new target(...(args as ConstructorParameters<typeof Intl.NumberFormat>))
    },
  }) as typeof Intl.NumberFormat
})

afterAll(() => {
  Intl.NumberFormat = RealNumberFormat
})

function rosterOf(count: number) {
  return Array.from({ length: count }, (_, i) => ({
    name: `Creative ${i}`,
    provider: i % 2 === 0 ? 'meta' : 'snapchat',
    content_key: `key-${i}`,
    format: 'image',
    metrics: { spend: 1000 + i * 7.31, impressions: 10_000 + i * 113, clicks: 300 + i, conversions: 12 + (i % 9), ctr: 0.03 + i / 10_000 },
    verified_result: { metric: 'purchases', path: null, value: 12 + (i % 9) },
  }))
}

function payloadWith(count: number): LivePayload {
  return {
    period: { from: '2026-08-18', to: '2026-09-16', days: 30 },
    currency: 'SAR',
    totals: { spend: 1000, conversions: 50, impressions: 100_000, clicks: 3000, revenue: 3000, cpa: 20, ctr: 0.03, cpc: 0.33, cpm: 10 },
    deltas: {},
    timeseries: [{ date: '2026-09-15', spend: 500, clicks: 1500, conversions: 25, impressions: 50_000 }],
    platforms: [
      { provider: 'meta', spend: 700, impressions: 70_000, clicks: 2100, conversions: 40, cpa: 17.5, ctr: 0.03, movement: { impressions: 0.1 } },
      { provider: 'snapchat', spend: 300, impressions: 30_000, clicks: 900, conversions: 10, cpa: 30, ctr: 0.03, movement: { impressions: -0.2 } },
    ],
    campaigns: [],
    funnel: [],
    ads: rosterOf(4).map(({ metrics, ...rest }) => ({ ...rest, ...metrics })),
    ads_weakest: rosterOf(4).map(({ metrics, ...rest }) => ({ ...rest, ...metrics })),
    ads_groups: [],
    ads_roster: rosterOf(count),
    results: { configured: true, total: 50, cost: 20, by_path: {}, events: {}, by_platform: { meta: { results: 40, metric: 'purchases', spend: 700, cost: 17.5 }, snapchat: { results: 10, metric: 'purchases', spend: 300, cost: 30 } } },
    creatives_in_scope: count,
    freshness: [],
    available: { providers: ['meta', 'snapchat'], campaigns: [], earliest: '2026-01-01', latest: '2026-09-16' },
    metrics: [],
    applied: { from: '2026-08-18', to: '2026-09-16', providers: [], campaigns: [] },
  } as unknown as LivePayload
}

const noop = () => undefined

function Tables({ payload, tick }: { payload: LivePayload; tick?: number }) {
  const reader = useLiveMetricReader('SAR', false)

  return <div data-tick={tick}><TablesView payload={payload} reader={reader} locale="en" onOpenContent={noop} /></div>
}

describe('the live roster (PERF-RENDER-001)', () => {
  it('draws 300 rows with formatter constructions bounded by the shapes used, not the rows', () => {
    const before = constructions
    const { unmount } = render(<Tables payload={payloadWith(300)} />)
    // `querySelectorAll`, not `getAllByRole`: a role query walks the accessibility tree of every cell.
    // REPORT-WEBSITE-TAB-001 — a fixed page of eight rows; the 300 are paged, never one long table.
    expect(screen.getByTestId('tables-content').querySelectorAll('tbody tr')).toHaveLength(8)
    expect(screen.getByTestId('tables-content')).toHaveTextContent('1–8 / 300')
    const first = constructions - before

    // Every (locale, options) pair the four tables format with — far fewer than one per figure.
    expect(first, 'formatters built for 300 rows').toBeLessThanOrEqual(12)
    unmount()

    // A second roster, with fresh figures, builds nothing: the formatters are keyed by shape, not by value or row.
    const again = constructions
    render(<Tables payload={payloadWith(60)} />)
    expect(screen.getByTestId('tables-content').querySelectorAll('tbody tr')).toHaveLength(8)

    expect(constructions - again, 'formatters built for 60 more rows').toBe(0)
    // Three hundred rows in jsdom is a slow DOM, not a slow formatter — the budget is for the DOM.
  }, 90_000)

  it('keeps its sort when the page above it re-renders', () => {
    function Page() {
      const [tick, setTick] = useState(0)

      return (
        <>
          <button type="button" data-testid="tick" onClick={() => setTick((n) => n + 1)}>tick</button>
          <Tables payload={payload} tick={tick} />
        </>
      )
    }
    const payload = payloadWith(30)
    render(<Page />)

    const table = screen.getByTestId('tables-content')
    const head = table.querySelector('thead th')!
    fireEvent.click(within(head as HTMLElement).getByRole('button'))
    expect(head).toHaveAttribute('aria-sort', 'descending')

    // A re-render of the parent used to remount every table and lose this.
    fireEvent.click(screen.getByTestId('tick'))
    expect(screen.getByTestId('tables-content').querySelector('thead th')).toHaveAttribute('aria-sort', 'descending')
  })

  it('re-renders no content tile when the grid above it re-renders with the same rows', () => {
    const payload = payloadWith(120)

    function Grid() {
      const [tick, setTick] = useState(0)

      return (
        <>
          <button type="button" data-testid="tick" onClick={() => setTick((n) => n + 1)}>tick</button>
          <div data-tick={tick}>
            <ContentView whole={payload} currency="SAR" locale="en" onOpenContent={noop} platform={null} onPlatform={noop} narrowed={{ state: 'pending' }} />
          </div>
        </>
      )
    }
    render(<Grid />)
    const drawn = vi.mocked(figuresFor).mock.calls.length
    expect(drawn).toBeGreaterThan(0)

    fireEvent.click(screen.getByTestId('tick'))
    fireEvent.click(screen.getByTestId('tick'))

    expect(vi.mocked(figuresFor).mock.calls.length, 'tiles re-rendered by a parent re-render').toBe(drawn)
  })
})
