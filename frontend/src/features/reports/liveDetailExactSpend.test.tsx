import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { LiveDetailTables } from './LiveDetailTables'
import { renderWithProviders } from '@/test/utils'
import type { LivePayload } from './api'

/**
 * NUMBER-PRESENTATION-001 — «meaningful precision kept, exact value reachable», on the money column.
 *
 * Measured on production: the platform table printed «10.7K USD» over a real 10,696.54 with nothing
 * to reach it, while the impressions beside it revealed 7,027,238 and the clicks 42,424. The reader
 * of that surface is the client whose money it is, and the abbreviation is the only figure they were
 * given.
 *
 * The cause was a deliberate blanket `null`, and its reason was right about half the cases: a «—»
 * that a tooltip turned back into a number would state exactly what the money contract refused to.
 * That protects a withheld amount by breaking a stated one. Both halves are asserted here, because
 * fixing the first by removing the refusal would be a worse defect than the one being fixed.
 */
const payload = (spend: number, over: Record<string, unknown> = {}) => ({
  period: { from: '2026-08-01', to: '2026-08-30' },
  currency: 'USD',
  totals: { spend, conversions: 607, revenue: 0, impressions: 7_027_238, clicks: 42_424 },
  platforms: [{ provider: 'snapchat', spend, conversions: 607, impressions: 7_027_238, clicks: 42_424, ...over }],
  campaigns: [], ad_sets: [], ads: [], ads_groups: [], funnel: [], metrics: [], freshness: [], outline: [],
  objective_performance: { paths: [] },
  store_funnel: null,
  available: { providers: ['snapchat'], campaigns: [], earliest: '2026-08-01', latest: '2026-08-30' },
  applied: {}, is_demo: false, form: 'detailed',
}) as unknown as LivePayload

describe('the money a client can only read as an abbreviation', () => {
  it('reveals the exact spend behind the compact one', async () => {
    renderWithProviders(
      <LiveDetailTables payload={payload(10_696.54)} currency="USD" locale="en" />, { locale: 'en' },
    )

    const compact = await screen.findByText(/10\.7K/)
    const cell = compact.closest('td')
    expect(cell).not.toBeNull()

    // The figure is reachable from the cell — through whatever element carries the revealed value.
    const holder = cell!.matches('[title]') ? cell! : cell!.querySelector('[title]')
    expect(holder, 'the spend cell revealed nothing behind «10.7K USD»').not.toBeNull()
    expect(holder!.getAttribute('title')).toContain('10,696.54')
  })

  /**
   * The other half, and the reason the blanket null existed. A figure the contract would not state
   * must not come back through the tooltip.
   */
  it('reveals nothing for an amount the contract refused to state', async () => {
    renderWithProviders(
      <LiveDetailTables
        payload={payload(0, { spend: null, spend_state: 'unavailable' })}
        currency="USD"
        locale="en"
      />,
      { locale: 'en' },
    )

    const dash = await screen.findAllByText('—')
    for (const el of dash) {
      const cell = el.closest('td')
      if (!cell) continue
      const holder = cell.matches('[title]') ? cell : cell.querySelector('[title]')
      expect(holder?.getAttribute('title') ?? '', 'a refused amount came back through the tooltip')
        .not.toMatch(/\d/)
    }
  })
})
