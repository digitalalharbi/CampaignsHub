import { describe, expect, it } from 'vitest'
import { withGa4Slides } from './reportSections'

/** REPORT-WEBSITE-TAB-001 — the saved report's pages follow the live link's tab order, and the summary strip sits with the summary. */
interface Page { id: string; type: string; order: number; visible: boolean; range?: [number, number] }

const data = {
  report_sections: ['ga4_overview', 'ga4_acquisition', 'ga4_attribution', 'ga4_platform_gap', 'ga4_gap_reasons', 'ga4_classification', 'ga4_tables', 'ga4_products', 'ga4_pulse'],
  ga4_pulse: { kpis: [] }, ga4_overview: {}, ga4_acquisition: {}, ga4_attribution: { platforms: [{}] }, ga4_platform_gap: {}, ga4_gap_reasons: {},
  ga4_classification: { groups: { channel: { rows: [{}] } } }, ga4_tables: { tables: { sources: { rows: [{}] } } }, ga4_products: { table: { rows: [{}] } },
}
const base: Page[] = [
  { id: 'cover', type: 'cover', order: 0, visible: true },
  { id: 'exec', type: 'executive_summary', order: 1, visible: true },
  { id: 'cmp', type: 'platform_comparison', order: 2, visible: true },
]

describe('the saved report and the PDF follow the website tab', () => {
  it('places the website pages in the live tab order and the headline strip right after the executive summary', () => {
    const slides = withGa4Slides<Page>([...base], data)

    expect(slides.map((s) => s.type)).toEqual([
      'cover', 'executive_summary', '__ga4_pulse', 'platform_comparison',
      '__ga4_overview', '__ga4_acquisition', '__ga4_attribution', '__ga4_gap', '__ga4_reasons', '__ga4_classification', '__ga4_tables', '__ga4_products',
    ])
  })

  it('never prints a page twice', () => {
    for (const paged of [false, true]) {
      const ids = withGa4Slides<Page>([...base], data, paged).map((s) => s.id)
      expect(new Set(ids).size, `paged=${paged}`).toBe(ids.length)
    }
  })

  it('gives a snapshot from before the parts existed no website page', () => {
    expect(withGa4Slides<Page>([...base], { ...data, report_sections: undefined })).toEqual(base)
  })
})
