import { beforeAll, describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import { renderWithProviders } from '@/test/utils'

beforeAll(() => {
  if (!(document as Document & { fonts?: unknown }).fonts) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

/** RESULT-EVENTS-001 (Owner 2026-10-29) — the PDF a link produces follows its Results contract and its KPI list. */
const platforms = [{ provider: 'tiktok', platform: 'tiktok', spend: 258, revenue: 0, roas: 0, conversions: 1126, results: 1126 }]
const doc = (extra: Record<string, unknown>) => (
  renderWithProviders(
    <PrintDocument
      data={{ period: { from: '2026-10-01', to: '2026-10-29' }, platforms, kpis: { spend: 258, conversions: 1126, cpa: 0.23, results: 13, cost_per_result: 19.8, clicks: 5525 }, ...extra } as never}
      reportName="Riwaq"
      currency="USD"
    />,
    { locale: 'en' },
  )
)

describe('the PDF follows the link\'s Results contract', () => {
  it('with no Results defined: no Results KPI, no Results column, and generic conversions only under their own name', () => {
    doc({ results: { configured: false, total: null, cost: null } })
    const text = document.body.textContent ?? ''

    expect(text).not.toMatch(/Results|Cost per result/)
    expect(text).toContain('Platform conversions')
  })

  it('with Results defined: the resolver\'s per-platform figure under the link\'s own name', () => {
    const defined = 13
    doc({ results: { configured: true, total: defined, cost: 19.8, by_platform: { tiktok: { results: defined, cost: 19.8 } } }, results_label: 'Interest' })
    const head = Array.from(document.querySelectorAll('th')).map((th) => th.textContent)

    expect(head).toContain('Interest')
    expect(screen.getAllByText(String(defined)).length).toBeGreaterThan(0)
    expect(document.body.textContent).not.toContain('1,126 ')
  })

  it('lists only the KPIs the link shows', () => {
    doc({ results: { configured: false, total: null, cost: null }, kpi_allowlist: ['spend', 'clicks'] })
    const text = document.body.textContent ?? ''

    // The hidden KPIs' own figures are absent, not merely their names.
    expect(text).not.toContain('1,126')
    expect(text).not.toContain('0.23')
    expect(text).toContain('5,525')
  })
})
