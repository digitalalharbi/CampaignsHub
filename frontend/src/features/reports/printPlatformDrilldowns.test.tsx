import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import type { PrintPlatformDrilldown } from './PrintPlatformDrilldowns'

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
  timeseries: [{ date: '2026-07-10', spend: 150 }, { date: '2026-07-11', spend: 150 }],
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
    expect(within(rows[1]).getByTestId('print-drilldown-absence')).toHaveTextContent('This content’s preview is not available right now.')
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
