import { describe, expect, it } from 'vitest'
import { deriveKpi } from './objectiveKpi'

describe('deriveKpi (campaign objective → KPIs, from engine metadata)', () => {
  it('derives primary/secondary KPIs, funnel and report template from metadata', () => {
    const d = deriveKpi({ kpi: ['roas', 'cpa', 'revenue'], funnel: 'conversion', template: 'performance' })
    expect(d.primary).toBe('roas')
    expect(d.secondary).toEqual(['cpa', 'revenue'])
    expect(d.funnel).toBe('conversion')
    expect(d.template).toBe('performance')
    expect(d.alerts).toContain('budget_risk')
  })

  it('never surfaces ROAS as primary nor leads at all for awareness (metadata-driven)', () => {
    const d = deriveKpi({ kpi: ['reach', 'impressions', 'cpm', 'frequency'], funnel: 'awareness', template: 'brand' })
    expect(d.primary).toBe('reach')
    expect(d.primary).not.toBe('roas')
    expect([d.primary, ...d.secondary]).not.toContain('leads')
    expect([d.primary, ...d.secondary]).not.toContain('roas')
  })

  it('falls back gracefully when the objective carries no metadata', () => {
    expect(deriveKpi(null)).toMatchObject({ primary: null, secondary: [], funnel: null, template: null, alerts: [] })
  })
})
