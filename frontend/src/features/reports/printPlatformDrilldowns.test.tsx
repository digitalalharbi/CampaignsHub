import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen, within, cleanup } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { printAbsence, type PrintPlatformDrilldown } from './PrintPlatformDrilldowns'
import { readPreview } from '@/features/content/adPreview'
import type { CreativePreview } from '@/features/content/api'

/**
 * REPORT-DRILLDOWN-001 — the PDF's optional platform drill-down section.
 *
 * Nothing is printed unless the server sent the section (it sends it only when the operator enabled
 * it). When it is there: objective KPIs, both shares, a trend, and content with the real picture or a
 * client-worded absence — never the operator's note about why the link failed, and never a campaign.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const base = {
  period: { from: '2026-07-01', to: '2026-07-31' },
  kpis: { spend: 400 },
  platforms: [{ platform: 'meta', spend: 300 }, { platform: 'tiktok', spend: 100 }],
} as never

const block: PrintPlatformDrilldown = {
  period: { from: '2026-07-01', to: '2026-07-31', days: 31 },
  provider: 'meta',
  objective: { key: 'sales', ranking: 'roas' },
  objectives: [{ path: 'conversion', label_ar: 'التحويل والمبيعات', label_en: 'Conversion & sales', metrics: { spend: 300, orders: 12, cpa: 25, revenue: 1500, roas: 5 } }],
  totals: { spend: 300 },
  // Two real days with a shape: a flat line is not a trend and would (rightly) draw nothing.
  timeseries: [{ date: '2026-07-10', spend: 150 }, { date: '2026-07-11', spend: 200 }],
  shares: { spend: { value: 300, total: 400, share: 0.75 }, outcome: { metric: 'revenue', value: 1500, total: 1700, share: 0.8824 } },
  ads: [
    { name: 'Summer hero', provider: 'meta', spend: 200, conversions: 8, preview: { state: 'available', kind: 'image', image_url: 'https://cdn.example.com/a.jpg', thumbnail_url: 'https://cdn.example.com/a.jpg' } },
    { name: 'Expired one', provider: 'meta', spend: 100, conversions: 4, preview: { state: 'expired', note_en: 'Platform link expired — resync the account' } },
  ] as never,
  ads_weakest: [],
}

describe('the PDF platform drill-down section', () => {
  it('is absent unless the server sent it', () => {
    render(<PrintDocument data={base} reportName="R" currency="SAR" />)
    expect(screen.queryByTestId('print-platform-drilldowns')).not.toBeInTheDocument()
  })

  it('prints KPIs, both shares, a trend and content with real pictures or client-worded absence', () => {
    render(<PrintDocument data={{ ...(base as object), platform_drilldowns: [block] } as never} reportName="R" currency="SAR" />)
    const section = screen.getByTestId('print-drilldown-meta')

    expect(within(section).getByTestId('print-drilldown-kpis-conversion')).toHaveTextContent('Cost per result')
    expect(within(section).getByTestId('print-drilldown-share-spend')).toHaveTextContent('75%')
    expect(within(section).getByTestId('print-drilldown-share-outcome')).toHaveTextContent('88.2%')
    expect(within(section).getByTestId('print-drilldown-trend')).toBeInTheDocument()

    const rows = within(section).getAllByTestId('print-drilldown-ad')
    expect(rows[0].querySelector('img')?.getAttribute('src')).toBe('https://cdn.example.com/a.jpg')
    expect(rows[1].querySelector('img')).toBeNull()
    expect(within(rows[1]).getByTestId('print-drilldown-absence')).toHaveTextContent('The preview is not available from the source right now; the performance figures are unaffected.')
    expect(section.textContent ?? '').not.toMatch(/resync|Platform link expired/)
    expect(section.textContent ?? '').not.toMatch(/campaign/i)
    expect(within(section).queryByTestId('print-drilldown-weakest')).not.toBeInTheDocument()
  })

  it('leaves the spend share out when the server withheld it', () => {
    render(<PrintDocument data={{ ...(base as object), platform_drilldowns: [{ ...block, shares: { ...block.shares, spend: null } }] } as never} reportName="R" currency="SAR" />)
    expect(screen.queryByTestId('print-drilldown-share-spend')).not.toBeInTheDocument()
    expect(screen.getByTestId('print-drilldown-share-outcome')).toBeInTheDocument()
  })
})

/**
 * A collection whose hero is a FILM must not print an empty absence span.
 *
 * `printAbsence` was `clientAbsence` written out a second time, and the copy had already drifted:
 * `clientAbsence` grew an arm for a film with no cover frame and this one did not, so the drilldown
 * rendered its absence span EMPTY for exactly the ads that arm was written for — a blank rectangle
 * with nothing beside it, on a page a client keeps.
 *
 * It became reachable when a collection carrying a video started resolving to a film reading rather
 * than to `src: null`.
 */
describe('a film with no cover frame, on a printed drilldown', () => {
  const film = {
    state: 'available',
    kind: 'collection',
    image_url: null,
    thumbnail_url: null,
    video_url: 'https://cdn.test/hero.mp4',
    note_ar: null,
    note_en: null,
  } as unknown as CreativePreview

  it('says what it is rather than printing nothing', () => {
    const sentence = printAbsence(readPreview(film, false), false)

    expect(sentence).not.toBe('')
    expect(sentence).toContain('no cover frame')
  })

  it('says it in Arabic on an Arabic report', () => {
    expect(printAbsence(readPreview(film, true), true)).toContain('فيديو')
  })
})

/** The Owner removed the spend-notes paragraph from every report footer (2026-10-19): the PDF carries none. */
describe('the printed footer', () => {
  it('carries no spend notes and no data-sources line', () => {
    const { container } = render(<PrintDocument data={base} reportName="R" currency="SAR" />)

    expect(screen.queryByTestId('print-document-spend-notes')).toBeNull()
    expect(container.textContent).not.toContain('official invoices are the reference')
    expect(container.textContent).not.toContain('Data sources')
  })
})

/**
 * REPORT-CHARTS-COMPLETE-001 — the printed trend under the chart contract.
 *
 * It used to coalesce a missing day to zero, pick spend whenever every day carried a number and
 * draw an unlabelled line. A printed page has no hover, so the line is captioned with its metric,
 * unit and window; a day nobody reported breaks the line; spend is plotted only when the link
 * discloses it; one point or a flat series draws nothing.
 */
describe('the printed drill-down trend reads through the one sparkline rule', () => {
  const withSeries = (timeseries: unknown[], extra: Partial<PrintPlatformDrilldown> = {}) =>
    render(<PrintDocument data={{ ...(base as object), platform_drilldowns: [{ ...block, ...extra, timeseries }] } as never} reportName="R" currency="SAR" />)

  it('leaves an unreported day as a break in the line and captions metric, unit and window', () => {
    withSeries([{ date: '2026-07-10', spend: 150 }, { date: '2026-07-11', clicks: 5 }, { date: '2026-07-12', spend: 200 }])
    const d = screen.getByTestId('print-drilldown-trend').querySelector('path')?.getAttribute('d') ?? ''
    expect(d.match(/M/g)?.length).toBe(2)
    const label = screen.getByTestId('print-drilldown-trend-label')
    expect(label).toHaveTextContent('Daily trend — Spend')
    expect(label).toHaveTextContent('SAR')
    expect(label).toHaveTextContent('2026-07-01 → 2026-07-31')
    expect(label).toHaveTextContent('Gaps are unreported days')
  })

  it('plots clicks, not spend, when the link hides spend — and says so', () => {
    withSeries(
      [{ date: '2026-07-10', spend: 150, clicks: 10 }, { date: '2026-07-11', spend: 160, clicks: 30 }],
      { shares: { ...block.shares, spend: null } },
    )
    const label = screen.getByTestId('print-drilldown-trend-label')
    expect(label).toHaveTextContent('Daily trend — Clicks')
    expect(label).not.toHaveTextContent('SAR')
  })

  it('draws nothing for one day, a flat series, or a series more than a third unreported', () => {
    withSeries([{ date: '2026-07-10', spend: 150 }])
    expect(screen.queryByTestId('print-drilldown-trend')).not.toBeInTheDocument()
    cleanup()
    withSeries([{ date: '2026-07-10', spend: 150 }, { date: '2026-07-11', spend: 150 }])
    expect(screen.queryByTestId('print-drilldown-trend')).not.toBeInTheDocument()
    cleanup()
    withSeries([{ date: '2026-07-10', spend: 150 }, { date: '2026-07-11' }, { date: '2026-07-12' }, { date: '2026-07-13', spend: 90 }])
    expect(screen.queryByTestId('print-drilldown-trend')).not.toBeInTheDocument()
  })
})
