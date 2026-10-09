import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SiteMeasurementSection } from './live/LiveSections'
import type { LivePayload } from './api'

/**
 * GA4-INTEGRATION-001 — the client's own site on the client's own report, and the one sentence that
 * keeps it from being added to the campaigns' figures.
 *
 * The owner's rule, verbatim: «Do not calculate a blended ROAS from incompatible attribution
 * sources.» On a page that already carries a revenue figure from the ad platforms, a second revenue
 * figure with no explanation IS a blend — the reader performs it. So the block states the difference
 * every time it is drawn, and these tests hold that, along with the two absences that must never
 * read as zeros.
 */

function measured(over: Partial<NonNullable<LivePayload['site_measurement']>> = {}) {
  return {
    site_measurement: {
      property: { id: '111', name: 'موقع العميل', timezone: 'Asia/Riyadh' },
      currency: 'SAR',
      from: '2026-07-15',
      to: '2026-07-16',
      days: 2,
      totals: {
        sessions: 4000, users: 3100, new_users: 900, page_views: 12_000,
        key_events: null, transactions: 17, revenue: 900,
      },
      rates: { engagement_rate: 0.75 },
      series: [],
      absent: ['key_events'],
      ...over,
    },
  } as unknown as LivePayload
}

describe('the site measurement block', () => {
  it('says in words that these figures are not added to the ad platforms’', () => {
    render(<SiteMeasurementSection payload={measured()} ar />)

    const note = screen.getByTestId('site-measurement-not-blended')
    expect(note.textContent).toContain('لا تُجمع مع أرقام المنصات الإعلانية')
  })

  /**
   * No return, anywhere in this block.
   *
   * A return needs a spend, and GA4 has none. The spend one section up was paid to platforms whose
   * attribution is not this one's, so any ratio drawn between them is the blend the rule forbids —
   * and «5.00x» beside a client's own traffic is exactly the number the owner reported as a «خطأ
   * فادح» when it appeared against the creatives.
   */
  it('computes no return and no ratio against the campaigns', () => {
    const { container } = render(<SiteMeasurementSection payload={measured()} ar />)

    expect(container.textContent).not.toMatch(/\d+(\.\d+)?x/)
    expect(container.textContent).not.toContain('ROAS')
    expect(container.textContent).not.toContain('العائد')
  })

  /** A metric the property does not measure is named as unmeasured, never drawn as a zero. */
  it('names an unmeasured metric instead of showing it as zero', () => {
    render(<SiteMeasurementSection payload={measured()} ar />)

    expect(screen.getByTestId('site-measurement-absent').textContent).toContain('الأحداث الرئيسية')
    expect(screen.queryByTestId('site-metric-key_events')).not.toBeInTheDocument()
  })

  /**
   * Withheld and unmeasured are said differently.
   *
   * «The operator hid this» and «this property does not measure it» are different facts. Merging
   * them would tell a reader the client's site has no ecommerce when the truth is that their agency
   * chose not to show the figure.
   */
  it('separates a figure the operator hid from one the property does not measure', () => {
    render(<SiteMeasurementSection payload={measured({
      totals: { sessions: 4000, users: 3100, new_users: 900, page_views: 12_000, key_events: null, transactions: 17, revenue: null },
      currency: null,
      absent: ['key_events'],
      withheld: ['revenue'],
    })} ar />)

    expect(screen.getByTestId('site-measurement-withheld').textContent).toContain('إيراد الموقع')
    expect(screen.getByTestId('site-measurement-absent').textContent).not.toContain('إيراد الموقع')
    expect(screen.queryByTestId('site-metric-revenue')).not.toBeInTheDocument()
    // The session count is not money and stays: hiding it would misrepresent the site's traffic.
    expect(screen.getByTestId('site-metric-sessions')).toBeInTheDocument()
  })

  /** The property's own currency is stated, never converted into the report's. */
  it('states the property’s own currency beside its revenue', () => {
    render(<SiteMeasurementSection payload={measured({ currency: 'USD' })} ar />)

    expect(screen.getByTestId('site-metric-revenue').textContent).toContain('USD')
  })

  /** A rate is a percentage of its own sessions, not a sum of fractions. */
  it('shows the engagement rate as a percentage', () => {
    render(<SiteMeasurementSection payload={measured()} ar />)

    expect(screen.getByTestId('site-metric-engagement_rate').textContent).toContain('75.0%')
  })

  /** No property, no block — rather than a block of dashes that reads as a failed load. */
  it('draws nothing at all when there is no measurement', () => {
    const { container } = render(<SiteMeasurementSection payload={{ site_measurement: null } as unknown as LivePayload} ar />)

    expect(container.innerHTML).toBe('')
  })
})
