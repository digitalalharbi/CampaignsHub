import { beforeAll, describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PrintDocument } from './PrintDocument'
import type { ReportData } from './InteractiveReport'

/**
 * REPORT-PRINT-001 — the A4 document is a DIFFERENT artefact from the deck, not a lesser one.
 *
 * `PrintDocument` is English, LTR and page-flowed where `SlideBody` is Arabic, RTL and 16:9. They are
 * deliberately separate renderers, so the fix here is not to converge them — it is that sections the
 * deck has were never written for this one, and that its section numbers were literals over
 * conditional sections.
 */

/*
 * jsdom implements no font-loading API, and the component signals print-readiness from
 * `document.fonts.ready`. Without this the effect throws and every case fails for a reason that has
 * nothing to do with what is being asserted. Stubbed as already-resolved, which is what the headless
 * Chromium that actually prints these documents reports once fonts settle.
 */
beforeAll(() => {
  if (!('fonts' in document)) {
    Object.defineProperty(document, 'fonts', { value: { ready: Promise.resolve() }, configurable: true })
  }
})

const base: ReportData = {
  period: { from: '2026-07-01', to: '2026-07-31' },
  currency: 'USD',
  objective: 'sales',
  kpis: { spend: 1000, revenue: 4000, conversions: 40, roas: 4, cpa: 25 },
  delta: {},
  timeseries: [],
  platforms: [{ platform: 'meta', spend: 1000, revenue: 4000, results: 40, roas: 4 }],
  campaigns: [],
  slides: [],
}

const doc = (data: ReportData) =>
  render(<PrintDocument data={data} reportName="July" currency="USD" clientName="Acme" />)

/** Every rendered `N. Title` heading, in document order. */
const numbers = () =>
  screen.getAllByRole('heading', { level: 2 })
    .map((h) => h.textContent ?? '')
    .filter((t) => /^\d+\./.test(t))
    .map((t) => Number(t.split('.')[0]))

describe('the printed document’s section numbering', () => {
  /**
   * THE DEFECT: «4. Budget Pacing» and «5. Recommendations» were literals, and both sections are
   * conditional. A report with no budget lines printed 1, 2, 3, 5 — and a reader of a client PDF
   * reads a missing 4 as a page lost in production, not as a section that did not apply.
   *
   * Worth stating plainly, because it changes what these tests are worth: with «Ad Sets» now always
   * rendered, that ORIGINAL arrangement happens to come out contiguous again — the new section
   * silently fills the hole the missing budget left. So the no-budget case alone no longer proves
   * anything, and a test named for it would be decoration. What still catches a literal is the
   * all-sections case, where hardcoded 4 and 5 collide with counted ones.
   *
   * Both configurations are asserted for holes AND duplicates, since a literal produces a repeat
   * before it produces a gap, and the counter is what keeps either impossible as sections come and
   * go later.
   */
  const isContiguousFromOne = (seen: number[]) => {
    expect(seen.length).toBeGreaterThan(1)
    expect(new Set(seen).size).toBe(seen.length)
    expect(seen).toEqual(Array.from({ length: seen.length }, (_, i) => i + 1))
  }

  it('numbers contiguously when the optional sections are absent', () => {
    doc({ ...base, budget: [], recommendations: [{ title: 'Shift budget to Meta' }] } as ReportData)

    isContiguousFromOne(numbers())
  })

  /** The case that actually fails against a hardcoded number — verified by reinstating one. */
  it('numbers contiguously when every optional section is present', () => {
    doc({
      ...base,
      budget: [{ name: 'Meta', budget: 2000, spend: 1000 }],
      recommendations: [{ title: 'Shift budget to Meta' }],
      top_creatives: [{ name: 'Ramadan hero', provider: 'meta', spend: 400, impressions: 90_000, ctr: 0.004 }],
      ad_sets: [{ entity_id: 'a', name: 'Riyadh · 25-34', spend: 400, impressions: 90_000, clicks: 300, ctr: 0.0033 }],
      entity_grains_reported: { ad_set: true },
    } as ReportData)

    isContiguousFromOne(numbers())
  })
})

describe('the sections the deck had and the document did not', () => {
  it('prints creative performance when the platform named creatives', () => {
    doc({
      ...base,
      top_creatives: [{ name: 'Ramadan hero', provider: 'meta', spend: 400, impressions: 90_000, ctr: 0.004 }],
    } as ReportData)

    expect(screen.getByText('Creative Performance', { exact: false })).toBeInTheDocument()
    expect(screen.getByText('Ramadan hero')).toBeInTheDocument()
  })

  /**
   * An empty creative table would state that the campaigns ran without creative. That never happened;
   * what happened is that the connector returned nothing at that grain.
   */
  it('omits the creative section entirely rather than printing an empty table', () => {
    doc({ ...base, top_creatives: [], worst_creatives: [] } as ReportData)

    expect(screen.queryByText('Creative Performance', { exact: false })).not.toBeInTheDocument()
  })

  it('says the platform did not break out ad sets, instead of showing none', () => {
    doc({ ...base, ad_sets: [], entity_grains_reported: { ad_set: false } } as ReportData)

    expect(screen.getByText(/did not report ad-set level figures/)).toBeInTheDocument()
  })

  /** A raw provider key in a client PDF answers a question nobody asked. */
  it('never prints a provider identifier for an unnamed ad set', () => {
    doc({
      ...base,
      ad_sets: [{ entity_id: 'a', external_id: 'sq-8f21c0', name: null, spend: 400, impressions: 9000, clicks: 30, ctr: 0.003 }],
      entity_grains_reported: { ad_set: true },
    } as ReportData)

    expect(screen.queryByText(/sq-8f21c0/)).not.toBeInTheDocument()
    expect(screen.getByText('Unnamed ad set')).toBeInTheDocument()
  })
})
