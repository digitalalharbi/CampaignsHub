import { describe, expect, it } from 'vitest'
import { creativeChips, creativeReadings, reportMetrics } from './reportMetrics'

const kpis = { spend: 120, impressions: 9000, leads: 40, cpl: 3, conversion_rate: 0.1, ctr: 0.02 }
const base = { objective: 'leads', kpis, currency: 'USD', metric_set: ['spend', 'impressions', 'leads', 'cpl'] }

/**
 * LEAD-DESTINATION-001 — a WhatsApp buy is judged on messaging results and what one cost, and its
 * figures carry the platform's own name for them. A website lead campaign is untouched.
 */
describe('a messaging destination', () => {
  it('names the result and its cost as messaging, not as leads', () => {
    const m = reportMetrics({ ...base, lead_destination: 'whatsapp' }, false)
    expect(m.map((x) => x.label)).toEqual(['Spend', 'Impressions', 'Messaging results', 'Cost per messaging result'])
  })

  it('names them in Arabic', () => {
    const m = reportMetrics({ ...base, lead_destination: 'direct_message' }, true)
    expect(m.map((x) => x.label)).toContain('تكلفة نتيجة المراسلة')
  })

  it('leaves a website lead campaign calling its results leads', () => {
    const m = reportMetrics({ ...base, lead_destination: 'landing_page' }, false)
    expect(m.map((x) => x.label)).not.toContain('Messaging results')
  })

  it('shows no purchase or ROAS chip on a creative of a messaging buy', () => {
    const chips = creativeChips('leads', 'whatsapp')
    expect(chips).toEqual(['leads', 'cpl', 'impressions', 'spend'])
    for (const banned of ['roas', 'revenue', 'purchases']) expect(chips).not.toContain(banned)
  })

  it('reads the creative on messaging results and their cost', () => {
    const r = creativeReadings({ leads: 12, cpl: 2.5, impressions: 900, spend: 30 }, 'leads', undefined, false, 'USD', 'whatsapp')
    expect(r.map((x) => x.label).slice(0, 2)).toEqual(['Messaging results', 'Cost per messaging result'])
  })
})
