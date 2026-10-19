import { describe, expect, it } from 'vitest'
import { healthSummary, type Finding } from './findings'

/**
 * RECS-OVERVIEW-ATF-001 — the four numbers at the top of the page are counted by SUBJECT, worst severity wins.
 */
const finding = (id: string, severity: Finding['severity'], subject: Finding['subject'], nature: Finding['nature'] = 'problem'): Finding => ({
  id, source: 'alert', code: 'x', severity, nature, subject, kpis: [], facts: [], trend: null, consumption: null, impact: null,
  action: { kind: 'open', label: 'Open' } as never, evidence: { target: 'campaign', path: '/' } as never,
})
const campaign = (id: string): Finding['subject'] => ({ type: 'campaign', id, name: id, platform: null })

describe('healthSummary', () => {
  it('counts a campaign once, as its worst severity, however many findings point at it', () => {
    const out = healthSummary([
      finding('1', 'warning', campaign('c1')),
      finding('2', 'critical', campaign('c1')),
      finding('3', 'warning', campaign('c2')),
      finding('4', 'info', campaign('c3'), 'tracking'),
    ])

    expect(out.critical).toBe(1)    // c1, not c1 twice
    expect(out.attention).toBe(1)   // c2; c1 is critical now
  })

  it('counts next actions as the findings a person can act on, observations aside', () => {
    const out = healthSummary([
      finding('1', 'critical', campaign('c1')),
      finding('2', 'warning', campaign('c2')),
      finding('3', 'info', campaign('c3'), 'opportunity'),
      finding('4', 'info', campaign('c4'), 'tracking'),
    ])

    expect(out.nextActions).toBe(3)
  })

  it('treats a limit and a connection as subjects of their own, separate from any campaign', () => {
    const out = healthSummary([
      finding('1', 'critical', { type: 'spend_limit', id: 'l1', name: 'Q4', platform: null }),
      finding('2', 'warning', { type: 'connection', id: 'k1', name: 'Meta', platform: 'meta' }),
      finding('3', 'warning', campaign('l1')),
    ])

    expect(out).toMatchObject({ critical: 1, attention: 2 })
  })

  it('is all zeros for nothing', () => {
    expect(healthSummary([])).toEqual({ critical: 0, attention: 0, nextActions: 0 })
  })
})
