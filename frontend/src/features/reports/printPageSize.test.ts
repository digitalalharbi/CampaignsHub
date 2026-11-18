import { describe, expect, it } from 'vitest'
import { SLIDE_PAGE } from './PrintReport'
import source from './PrintReport.tsx?raw'

/**
 * REPORT-PDF-16-9-001 (master task 10) — «العرض التقديمي وPDF: كل صفحة بحجم محتواها بالضبط، بلا هوامش (مثل شرائح 16:9)».
 *
 * The print script measures and fits every slide in a 1280×720 viewport; the page it then printed was A4 landscape, so the approved
 * layout was not the printed one and every slide carried a blank band. The slide page is now exactly that viewport, margin 0.
 */
describe('the presentation page size', () => {
  it('is a 16:9 slide of exactly the 1280×720 viewport the layout gate measures', () => {
    const [w, h] = SLIDE_PAGE.split(' ').map((v) => parseFloat(v))
    expect(w / h).toBeCloseTo(16 / 9, 3)
    // 96 CSS px per inch, 25.4 mm per inch.
    expect(Math.round((w / 25.4) * 96)).toBe(1280)
    expect(Math.round((h / 25.4) * 96)).toBe(720)
  })

  it('prints a presentation on that page with no margin', () => {
    expect(source).toContain("@page { size: ${landscape ? SLIDE_PAGE : 'A4 portrait'}; margin: 0; }")
    expect(source).not.toMatch(/size: A4 \$\{landscape/)
  })
})
