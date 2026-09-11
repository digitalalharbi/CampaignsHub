import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'

/**
 * REPORT-ANALYTICAL-DEPTH-001 — the printed budget table read a field the server does not send.
 *
 * `budgetPacingByProvider` emits `spent`. This document read `b.spend ?? 0`, which is `undefined ?? 0`
 * on every row, so the PDF a client receives showed a spend of zero against every platform, a
 * remaining equal to the whole budget, and 0% utilization — for accounts that had spent the money.
 *
 * The utilization was hand-computed too, over `Math.max(1, budget)`, so a platform with spend and no
 * budget divided by one riyal and printed a percentage in the hundreds of thousands. The server
 * already states `remaining`, `consumed_pct` and `pacing_basis`, and already REFUSES them when the
 * figures are not comparable; the document's job is to print that verdict, not to recompute it.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const outline = [
  { key: 'budget', title_ar: 'الميزانية', title_en: 'Budget', present: true, absent_reason: null },
]

const doc = (budget: unknown[]) =>
  render(<PrintDocument data={{ period: { from: '2026-08-01', to: '2026-08-30' }, kpis: {}, summary: [], platforms: [], recommendations: [], budget, outline } as never} currency="SAR" reportName="R" clientName="C" />)

describe('the printed budget table', () => {
  it('prints the spend the server actually sent', () => {
    doc([{ provider: 'meta', budget: 10000, spent: 7500, remaining: 2500, consumed_pct: 0.75, pacing_basis: 'comparable', budget_currency: 'SAR' }])

    const row = screen.getByText('meta').closest('tr')!
    expect(row.textContent).toContain('7,500')
    expect(row.textContent).toContain('2,500')
    expect(row.textContent).toContain('75%')
  })

  it('refuses a utilization the server refused rather than dividing by one', () => {
    doc([{ provider: 'tiktok', budget: 0, spent: 4000, remaining: null, consumed_pct: null, pacing_basis: 'no_budget', budget_currency: 'SAR' }])

    const row = screen.getByText('tiktok').closest('tr')!
    expect(row.textContent).not.toMatch(/\d{4,}\s*%/)
    expect(row.textContent).toContain('—')
  })

  it('does not print a withheld spend as zero', () => {
    doc([{ provider: 'google', budget: 5000, spent: null, remaining: null, consumed_pct: null, pacing_basis: 'partial', budget_currency: 'SAR' }])

    const row = screen.getByText('google').closest('tr')!
    expect(row.textContent).not.toMatch(/0\.00|٠/)
    expect(row.textContent).toContain('—')
  })
})
