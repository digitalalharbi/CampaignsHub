import { describe, expect, it } from 'vitest'
import { reportLabel } from './reportMetrics'

/** LEAD-EVIDENCE-001 — «Leads» is never the name of a platform's optimised-for conversions. */
describe('lead labels follow evidence', () => {
  it('names conversions «Leads» only where the platform also reported leads', () => {
    expect(reportLabel('conversions', 'leads', null, false, { leads: true })).toBe('Leads')
    expect(reportLabel('conversions', 'leads', null, false)).toBe('Leads')
  })

  it('keeps the catalogue word (Results) where no lead metric was sent', () => {
    expect(reportLabel('conversions', 'leads', null, false, { leads: false })).toBeNull()
    expect(reportLabel('cpa', 'leads', null, true, { leads: false })).toBeNull()
  })
})
