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
      '__ga4_overview', '__ga4_acquisition', '__ga4_attribution', '__ga4_reasons', '__ga4_classification', '__ga4_tables', '__ga4_products',
    ])
  })

  it('prints one comparison page and one products table: the match replaces the older comparison, the products part owns products', () => {
    const withProducts = { ...data, ga4_tables: { tables: { sources: { rows: [{}] }, products: { rows: [{}] } } } }
    const paper = withGa4Slides<Page>([...base], withProducts, true)

    expect(paper.some((s) => s.type === '__ga4_gap')).toBe(false)
    expect(paper.filter((s) => s.id.startsWith('__ga4_table:products'))).toEqual([])
    expect(paper.filter((s) => s.type === '__ga4_products')).toHaveLength(1)
    // Without the attribution part the older comparison stands, and without the products part the sources table keeps its products.
    const noMatch = withGa4Slides<Page>([...base], { ...withProducts, report_sections: (data.report_sections ?? []).filter((k) => k !== 'ga4_attribution' && k !== 'ga4_products') }, true)
    expect(noMatch.some((s) => s.type === '__ga4_gap')).toBe(true)
    expect(noMatch.some((s) => s.id.startsWith('__ga4_table:products'))).toBe(true)
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
