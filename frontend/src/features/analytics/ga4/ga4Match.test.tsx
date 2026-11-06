import { describe, expect, it, vi } from 'vitest'
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { renderWithProviders } from '@/test/utils'
import { FIXED_ROW_HEIGHT, fixedTableHeight } from '@/components/ui/FixedPageTable'
import { AttributionMatchBlock, AttributionTable, GapReasonsBlock, barWidths, campaignRows, platformRows } from './Ga4MatchBlock'
import { Ga4VisibilityControl } from './Ga4VisibilityBar'
import { Ga4Workbench } from './Ga4Workbench'
import type { GapReasonsPayload, MatchCampaign, MatchPayload, MatchPlatform, MatchSide } from './matchTypes'
import { ALL_KEYS, MARKETING_KEYS, SALES_KEYS, forPreset, hideParam, presetOf } from './visibility'

/**
 * GA4-ATTRIBUTION-MATCH-001 — the match table, its drill-down, the reasons of the gap and the visibility control.
 * jsdom lays nothing out: a table's box is held by its stated height, row count and row height.
 */
const side = (sessions: number, purchases: number): MatchSide => ({ sessions, purchases, revenue: purchases * 100, revenue_usd: purchases * 25, aov_usd: purchases ? 25 : null, conversion_rate: sessions ? purchases / sessions : null })
const camp = (i: number, confidence: MatchCampaign['confidence']): MatchCampaign => ({
  platform: 'meta', external_id: `id-${i}`, name: `campaign-${String(i).padStart(2, '0')}`, confidence,
  claimed: { conversions: 10 + i, revenue_usd: 100, spend_usd: 50, clicks: 400 }, ga4: confidence === 'unmatched' ? null : side(100 + i, 5),
  gap: { conversions: confidence === 'unmatched' ? null : 5 + i, conversions_share: confidence === 'unmatched' ? null : 0.3, revenue_usd: null, revenue_share: null }, spend_usd: 50, cost_per_purchase_usd: 10, roas_ga4: 2,
})
const platform = (n: number): MatchPlatform => ({
  platform: 'meta', claimed: { conversions: 100, revenue_usd: 500 }, ga4: side(900, 40),
  campaigns: [camp(1, 'id'), camp(2, 'utm'), camp(3, 'unmatched'), ...Array.from({ length: n }, (_, i) => camp(i + 4, 'utm'))],
  channel: { confidence: 'channel', ga4: side(50, 2) }, matched_share: 0.9,
})

describe('the attribution match table', () => {
  it('draws one confidence chip per row and a gap chip with its share, and names the basis as GA4', () => {
    renderWithProviders(<AttributionTable rows={campaignRows(platform(0))} ar={false} internal onDrill={() => undefined} testKey="meta" />, { locale: 'en' })

    expect(screen.getAllByTestId('confidence-id')).toHaveLength(1)
    expect(screen.getAllByTestId('confidence-utm')).toHaveLength(1)
    expect(screen.getAllByTestId('confidence-unmatched')).toHaveLength(1)
    expect(screen.getAllByTestId('confidence-channel')).toHaveLength(1)
    expect(screen.getByTestId('match-gap-campaign-id-1')).toHaveTextContent('+6')
    expect(screen.getByTestId('match-gap-campaign-id-1')).toHaveTextContent('30%')
    // An unmatched campaign has no GA4 figure and no gap: dashes, never zeroes.
    expect(screen.getByTestId('match-gap-campaign-id-3')).toHaveTextContent('—')
  })

  it('keeps the same box at the platform level and at the campaign level, with 3 rows or 30', () => {
    const heights: string[] = []
    for (const n of [0, 30]) {
      const { unmount } = renderWithProviders(<AttributionTable rows={campaignRows(platform(n))} ar={false} internal onDrill={() => undefined} testKey={`k${n}`} />, { locale: 'en' })
      const box = screen.getByTestId('match-table-box')
      heights.push(box.style.height)
      expect(box.querySelectorAll('tbody tr')).toHaveLength(8)
      unmount()
    }
    const level1 = renderWithProviders(<AttributionTable rows={platformRows([platform(0)])} ar={false} internal onDrill={() => undefined} testKey="platforms" />, { locale: 'en' })
    heights.push(screen.getByTestId('match-table-box').style.height)
    level1.unmount()

    expect(new Set(heights)).toEqual(new Set([`${fixedTableHeight(8) + 2}px`]))
    expect(FIXED_ROW_HEIGHT).toBeGreaterThan(0)
  })

  it('drills through a platform row and an id-matched campaign row, and not through an unmatched one', () => {
    const onDrill = vi.fn()
    renderWithProviders(<AttributionTable rows={campaignRows(platform(0))} ar={false} internal onDrill={onDrill} testKey="meta" />, { locale: 'en' })

    fireEvent.click(screen.getByTestId('match-drill-campaign-id-1'))
    expect(onDrill).toHaveBeenCalledWith(expect.objectContaining({ kind: 'campaign', name: 'campaign-01' }))
    expect(screen.queryByTestId('match-drill-campaign-id-3')).toBeNull()
  })

  it('shows a client report only the platforms: no campaign names and no ROAS column', () => {
    const client: MatchPayload = {
      state: 'ready', basis: 'ga4', order_level: false,
      platforms: [{ platform: 'meta', claimed: { conversions: 100, revenue_usd: 500 }, ga4: side(900, 40), confidence: 'utm', matched_campaigns: 2, gap: { conversions: 60, conversions_share: 0.6, revenue_usd: null, revenue_share: null }, matched_share: 0.8 }],
      unattributed: { sessions: 300, classes: [] }, unmatched: null,
    }
    renderWithProviders(<AttributionMatchBlock data={client} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('match-basis')).toHaveTextContent('GA4')
    expect(screen.getByTestId('confidence-utm')).toBeInTheDocument()
    expect(screen.queryByText('campaign-01')).toBeNull()
    expect(screen.queryByText('ROAS')).toBeNull()
    expect(screen.getByTestId('bucket-unattributed')).toHaveTextContent('300')
  })
})

const reasons: GapReasonsPayload = {
  basis: 'ga4', order_level: false, state: 'ready',
  headline: { unit: 'conversions', claimed: 80, measured: 52, gap: 28, gap_share: 0.35, gap_usd: 1000, excluded_campaigns: 0 },
  bar: [{ key: 'no_trace', value: 10, share: 0.357 }, { key: 'attribution_rule', value: 33, share: 1.178 }, { key: 'untagged_channel', value: -5, share: -0.178 }, { key: 'no_platform_trace', value: -10, share: -0.357 }, { key: 'unexplained', value: 0, share: 0 }],
  reasons: [
    { key: 'no_trace', label_ar: 'حملات بلا أثر', label_en: 'No GA4 trace', measured: true, value: 10, value_usd: 500, share: 0.357, direction: 'widens', cause_unmeasured: false, rows: [{ platform: 'meta', campaign: 'ghost', claimed: 10, ga4: null }] },
    { key: 'no_platform_trace', label_ar: 'مبيعات بلا منصة', label_en: 'No platform trace', measured: true, value: -10, value_usd: null, share: -0.357, direction: 'narrows', cause_unmeasured: false, rows: [] },
  ],
  unexplained: { key: 'unexplained', value: 0 },
  unmeasured: [{ key: 'view_through', label_ar: 'مشاهدة دون نقر', label_en: 'View-through', measured: false, value: null }, { key: 'timing', label_ar: 'تأخر التحويل', label_en: 'Conversion lag', measured: false, value: null }],
  context: [{ key: 'store_total', measured: false, value: null, unit: 'orders' }, { key: 'returning', measured: true, value: 0.45, unit: 'share' }],
  check: { sum: 28, gap: 28 },
}

describe('why the gap — the reasons panel', () => {
  it('states the gap once, draws a segment per measured reason, and draws the unmeasured as grey chips with no number', () => {
    renderWithProviders(<GapReasonsBlock data={reasons} ar={false} />, { locale: 'en' })

    expect(screen.getByTestId('gap-headline-gap')).toHaveTextContent('+28')
    expect(screen.getByTestId('gap-headline-gap')).toHaveTextContent('35%')
    expect(screen.getByTestId('gap-seg-attribution_rule')).toBeInTheDocument()
    expect(screen.getByTestId('gap-seg-no_platform_trace')).toHaveAttribute('data-narrows', 'true')
    expect(screen.queryByTestId('gap-seg-unexplained'), 'a zero remainder draws no segment but its chip is explicit').toBeNull()
    expect(screen.getByTestId('gap-unexplained')).toBeInTheDocument()
    const unmeasured = screen.getByTestId('gap-unmeasured-view_through')
    expect(unmeasured).toHaveTextContent('Not measured')
    expect(unmeasured.textContent).not.toMatch(/\d/)
    expect(screen.getByTestId('gap-context-store_total')).toHaveTextContent('Not measured')
    expect(screen.getByTestId('gap-context-returning')).toHaveTextContent('45%')
  })

  it('sizes the segments so that, with the sign, they account for the whole gap', () => {
    const widths = barWidths(reasons.bar!)
    expect(widths.reduce((a, b) => a + b.pct, 0)).toBeCloseTo(100, 0)
    expect(barWidths(reasons.bar!).find((w) => w.key === 'no_trace')?.narrows).toBe(false)
  })

  it('opens the rows behind a reason when it is pressed, and stays keyboard reachable', () => {
    const onReason = vi.fn()
    renderWithProviders(<GapReasonsBlock data={reasons} ar={false} onReason={onReason} />, { locale: 'en' })

    const chip = screen.getByTestId('gap-reason-no_trace')
    expect(chip.tagName).toBe('BUTTON')
    fireEvent.click(chip)
    expect(onReason).toHaveBeenCalledWith(expect.objectContaining({ key: 'no_trace' }))
  })
})

describe('the visibility control', () => {
  it('names the presets by their elements and recognises a custom state', () => {
    expect(presetOf([])).toBe('all')
    expect(presetOf(forPreset('marketing'))).toBe('marketing')
    expect(presetOf(forPreset('sales'))).toBe('sales')
    expect(presetOf(['tile.revenue'])).toBe('custom')
    expect(new Set([...SALES_KEYS, ...MARKETING_KEYS]).size).toBe(ALL_KEYS.length)
    expect(hideParam([])).toBe('')
    expect(hideParam(['tile.revenue', 'col.aov'])).toBe('&hide=col.aov%2Ctile.revenue')
  })

  it('hides sales in one click and switches a single element on its own', () => {
    const calls: string[][] = []
    function Harness() {
      return <Ga4VisibilityControl hidden={calls.at(-1) ?? []} onChange={(n) => { calls.push(n) }} ar={false} />
    }
    const { rerender } = renderWithProviders(<Harness />, { locale: 'en' })

    fireEvent.click(screen.getByTestId('ga4-visibility-preset-marketing'))
    expect(presetOf(calls.at(-1)!)).toBe('marketing')

    rerender(<Harness />)
    expect(screen.getByTestId('ga4-visibility')).toHaveAttribute('data-preset', 'marketing')
    fireEvent.click(screen.getByTestId('ga4-visibility-preset-custom'))
    const revenue = within(screen.getByTestId('ga4-visibility-elements')).getByTestId('ga4-visibility-el-tile.revenue') as HTMLInputElement
    expect(revenue.checked).toBe(false)
    fireEvent.click(revenue)
    expect(calls.at(-1)).not.toContain('tile.revenue')
  })
})

describe('the workbench', () => {
  it('is a single wrapper that does not crash without a connected property', async () => {
    renderWithProviders(<Ga4Workbench projectId="p1" range={{ from: '2026-10-01', to: '2026-10-10' }} ar={false} />, { locale: 'en' })
    await waitFor(() => expect(screen.getByTestId('ga4-workbench')).toBeInTheDocument())
    expect(screen.getByTestId('ga4-visibility')).toBeInTheDocument()
    expect(screen.getByTestId('ga4-filter-bar')).toBeInTheDocument()
  })
})
