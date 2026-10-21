import { describe, expect, it } from 'vitest'
import { basisLine } from './ExecutiveSummary'

/** REPORT-BEST-NOT-SPEND-001 — the headline order names its family, never a money metric, and only when the scope mixed objectives. */
describe('basisLine', () => {
  const basis = { family: 'sales', metric: 'roas', label_ar: 'العائد على الإنفاق', label_en: 'ROAS', family_label_ar: 'المبيعات', family_label_en: 'Sales' }

  it('names the family and not the ROAS it was ranked on', () => {
    expect(basisLine(basis, true)).toBe('مرتّبة على أداء المبيعات')
    expect(basisLine(basis, false)).toBe('Ranked by sales performance')
    expect(basisLine(basis, false)).not.toMatch(/ROAS|spend/i)
  })

  it('says nothing for a single-objective scope, an older server, or a scope nothing could rank', () => {
    expect(basisLine({ ...basis, family: null }, true)).toBeNull()
    expect(basisLine(undefined, true)).toBeNull()
    expect(basisLine({ ...basis, metric: null }, false)).toBeNull()
  })
})
