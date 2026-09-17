import { describe, expect, it } from 'vitest'
import { sectionShown } from './reportSections'

describe('sectionShown', () => {
  it('draws only what the server resolved', () => {
    const payload = { report_sections: ['kpis', 'funnel'] }
    expect(sectionShown(payload, 'kpis')).toBe(true)
    expect(sectionShown(payload, 'trends')).toBe(false)
    expect(sectionShown(payload, 'advanced_segmentation')).toBe(false)
  })

  it('an empty list hides everything — it is not «no list»', () => {
    expect(sectionShown({ report_sections: [] }, 'kpis')).toBe(false)
  })

  it('a payload from before the section model keeps drawing what it holds', () => {
    expect(sectionShown({}, 'budget_pacing')).toBe(true)
    expect(sectionShown(null, 'budget_pacing')).toBe(true)
  })
})
