import { describe, expect, it } from 'vitest'
import { screen, within } from '@testing-library/react'
import { LivePlatformComparison } from './LiveDetailTables'
import { renderWithProviders } from '@/test/utils'
import type { LivePayload } from './api'

/**
 * LIVE-CROSS-PLATFORM-001 — the comparison columns, and the figures they are allowed to print.
 *
 * The first version of this table derived `cost_per_result` and `ctr` in the browser from spend,
 * conversions, clicks and impressions. That was a second arithmetic for a figure the aggregator
 * already computes and publishes on every platform row — the shape that lets a page and the export
 * of itself disagree about one number — and it bypassed the money contract on the way: a cost per
 * result over a WITHHELD spend came out as a small number and read as cheap.
 *
 * So the columns are the server's, read through `readCostPer`, and what is pinned here is what the
 * table may and may not state:
 *
 *   - a platform NAME, never its database key
 *   - the server's own cost per result and rate, printed as given
 *   - «—» where the payload has no figure, and «—» where the contract refuses one
 *   - no «Infinity» and no «NaN», ever, on a page a client opens
 */
const payload = (platforms: Array<Record<string, unknown>>) => ({
  period: { from: '2026-08-01', to: '2026-08-30' },
  currency: 'SAR',
  totals: { spend: 0, conversions: 0, revenue: 0, impressions: 0, clicks: 0 },
  platforms,
  campaigns: [], ad_sets: [], ads: [], ads_groups: [], funnel: [], metrics: [], freshness: [], outline: [],
  objective_performance: { paths: [] },
  store_funnel: null,
  available: { providers: [], campaigns: [], earliest: '2026-08-01', latest: '2026-08-30' },
  applied: {}, is_demo: false, form: 'detailed',
}) as unknown as LivePayload

/**
 * RESULT-EVENTS-001 — Results (and their cost) exist only where the link DEFINES them; then each platform's figures are the resolver's
 * (`results.by_platform`). Built here from the rows' own counts and the cost the aggregator published, so no figure is invented by the test.
 */
const withResults = (p: LivePayload, platforms: Array<Record<string, unknown>>) => ({
  ...p,
  results: {
    configured: true, total: null, cost: null, by_path: {}, events: {},
    by_platform: Object.fromEntries(platforms.map((row) => [String(row.provider), { results: Number(row.conversions ?? 0), metric: 'purchases', spend: Number(row.spend ?? 0), cost: (row.cpa ?? null) as number | null }])),
  },
}) as unknown as LivePayload

const render = (platforms: Array<Record<string, unknown>>, defined = true) => {
  renderWithProviders(
    <LivePlatformComparison payload={defined ? withResults(payload(platforms), platforms) : payload(platforms)} currency="SAR" locale="en" />,
    { locale: 'en' },
  )

  return within(screen.getByTestId('live-platform-comparison'))
}

/**
 * One row's cell under a named column — by HEADER, never by index.
 *
 * The first version of the withheld-spend case read `getAllByRole('cell')[3]` and passed under an
 * injected browser-side division that made the cell print «0». The table's first column renders as a
 * row header rather than a cell, so the positional read was one column off and was asserting on
 * impressions — a test that passed for a reason unrelated to its name. Columns are found by their
 * heading here, and the offset is derived rather than assumed.
 */
function cellUnder(table: ReturnType<typeof within>, header: string): string {
  const headers = table.getAllByRole('columnheader').map((h: HTMLElement) => h.textContent?.trim() ?? '')
  const column = headers.findIndex((h: string) => h.startsWith(header))
  expect(column, `no column headed «${header}»`).toBeGreaterThan(-1)

  // The name column is a row header, so the cells of a row start one column in.
  return table.getAllByRole('cell')[column - 1]?.textContent ?? ''
}

describe('the platform comparison table', () => {
  it('names the platform rather than its database key', () => {
    const table = render([{ provider: 'snapchat', spend: 100, conversions: 10, cpa: 10 }])

    expect(table.getByText('Snapchat')).toBeInTheDocument()
    expect(table.queryByText('snapchat')).toBeNull()
  })

  /**
   * The figure is the SERVER's, and `cpa` is deliberately not `spend / conversions` here.
   *
   * The aggregator's own cost per result is computed over the rows it summed, which is not always
   * the quotient of two rounded totals. A test whose fixture makes the two agree cannot tell a page
   * that reads the published figure from one that re-derives it — and re-deriving it was the defect.
   */
  it('prints the cost per result the aggregator published, not a quotient of its own', () => {
    const table = render([{ provider: 'meta', spend: 1000, conversions: 40, cpa: 31.5 }])

    const cost = cellUnder(table, 'Cost per result')
    expect(cost).toContain('31.5')
    // 1000 / 40 — the quotient a page that re-derived the column would have printed.
    expect(cost).not.toContain('25')
  })

  it('prints the click-through rate as a rate, not as its raw fraction', () => {
    const table = render([{ provider: 'meta', impressions: 10_000, clicks: 250, ctr: 0.025 }])

    expect(table.getByText('2.50%')).toBeInTheDocument()
  })

  /**
   * A platform that reported no results has no cost per result — and the aggregator says so with a
   * null rather than with a division. The row stays: «this platform bought nothing» is a finding.
   */
  it('keeps the row of a platform that reported no results, and refuses its cost', () => {
    const table = render([{ provider: 'x', spend: 400, conversions: 0, cpa: null }])

    expect(table.getAllByText('X').length).toBeGreaterThan(0)
    expect(table.queryByText(/Infinity|NaN/)).toBeNull()
  })

  /**
   * FX-001 — a withheld spend does not become a cheap result.
   *
   * `spend` is null and the original is held in its own currency. A browser-side division would have
   * read the null as zero and printed a cost per result of 0; the contract states the figure in the
   * currency it was recorded in, or refuses it, and never in this report's.
   */
  it('never states a withheld spend under this report currency', () => {
    const table = render([{
      provider: 'meta',
      spend: null,
      spend_original: 4128.93,
      money_original_currency: 'USD',
      money_original_currencies: 1,
      spend_withheld_rows: 3,
      conversions: 100,
      cpa: null,
    }])

    const text = table.getByRole('table').textContent ?? ''
    expect(text).not.toMatch(/Infinity|NaN/)
    // The amount, where it appears at all, carries the currency it was recorded in.
    if (text.includes('4,128.93')) expect(text).toContain('USD')
    expect(text).not.toMatch(/4,128\.93 SAR/)

    // The resolver withholds a cost over withheld money: the cell says so, and never prints an amount under this report's currency.
    const cost = cellUnder(table, 'Cost per result')
    expect(cost).toMatch(/Not available/)
    expect(cost).not.toContain('SAR')
  })

  it('has no Results or cost-per-result column where the link defines no Results — not generic conversions, not clicks', () => {
    const table = render([{ provider: 'meta', spend: 1000, conversions: 40, cpa: 31.5, clicks: 900 }], false)
    const headers = table.getAllByRole('columnheader').map((h: HTMLElement) => h.textContent ?? '')

    expect(headers.join('|')).not.toMatch(/Results|Cost per result|Cost per click/)
    expect(table.getByRole('table').textContent).not.toContain('31.5')
  })

  it('says the window is empty rather than drawing a table with no rows', () => {
    const table = render([])

    expect(table.getByText(/No rows in this period/)).toBeInTheDocument()
    expect(table.queryByRole('table')).toBeNull()
  })

  it('keeps one row per platform', () => {
    const table = render([
      { provider: 'meta', spend: 10, conversions: 1, cpa: 10 },
      { provider: 'snapchat', spend: 20, conversions: 1, cpa: 20 },
      { provider: 'tiktok', spend: 30, conversions: 1, cpa: 30 },
    ])

    // Three platforms plus the header row.
    expect(table.getAllByRole('row')).toHaveLength(4)
  })

  it('puts a restrained provider mark beside each platform name, never instead of it', () => {
    const table = render([{ provider: 'meta', spend: 400, conversions: 4 }])

    const mark = table.getByTestId('provider-mark')
    expect(mark).toHaveAttribute('data-provider', 'meta')
    expect(mark).toHaveAttribute('aria-hidden', 'true')
    expect(mark.parentElement?.textContent).toMatch(/Meta/i)
  })

  /* REPORT-TABLES-OWNER-COLUMNS-001 */
  it('shows exactly the columns the Owner chose, always keeping the platform name', () => {
    const rows = [{ provider: 'meta', spend: 400, conversions: 4, clicks: 9 }]
    const p = { ...withResults(payload(rows), rows), columns: { platforms: ['clicks', 'results'] } } as unknown as LivePayload
    renderWithProviders(<LivePlatformComparison payload={p} currency="SAR" locale="en" />, { locale: 'en' })
    const table = within(screen.getByTestId('live-platform-comparison'))

    const headers = table.getAllByRole('columnheader').map((h: HTMLElement) => h.textContent?.trim() ?? '')
    expect(headers.map((h: string) => h.replace(/[↕↓↑]/g, ''))).toEqual(['Platform', 'Results', 'Clicks'])
  })

  it('treats the server’s empty list as «no restriction» and shows every column', () => {
    const rows = [{ provider: 'meta', spend: 400, conversions: 4 }]
    const p = { ...withResults(payload(rows), rows), columns: [] } as unknown as LivePayload
    renderWithProviders(<LivePlatformComparison payload={p} currency="SAR" locale="en" />, { locale: 'en' })
    const table = within(screen.getByTestId('live-platform-comparison'))

    expect(table.getAllByRole('columnheader')).toHaveLength(7)
  })
})
