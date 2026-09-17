import { describe, expect, it } from 'vitest'
import { buildActionCentre } from './actionCenter'
import { buildFindings, hasEvidence, relativeChange, toFinding, type FindingContext } from './findings'
import type { AlertEvent } from '@/features/alerts/api'
import type { SpendLimitReading } from '@/features/budget/spendLimitsApi'

/**
 * RECOMMENDATIONS-VISUAL-001 — the rules a finding card stands on.
 *
 * The cards are figures, so the rules are about figures: nothing is shown without something measured
 * behind it, a movement is drawn only where a before exists, impact is arithmetic on stated figures in
 * a stated currency, and nothing is converted, averaged or guessed.
 */
const ctx: FindingContext = {
  projectId: 'p1',
  currency: 'SAR',
  creativeCurrency: 'USD',
  campaigns: new Map([['c1', { name: 'Riyadh launch', provider: 'meta' }]]),
}

const CAMPAIGN = 'App\\Domains\\Campaigns\\Models\\UnifiedCampaign'

const alert = (over: Partial<AlertEvent> = {}): AlertEvent => ({
  id: 'a1', project_id: 'p1', rule_id: 'r', type: 'roas_drop', entity_type: CAMPAIGN, entity_id: 'c1',
  status: 'open', severity: 'warning', context: { roas_previous: 4, roas_current: 2.5 },
  notification_id: null, task_id: null, last_triggered_at: null, snoozed_until: null, resolved_at: null, created_at: null,
  ...over,
})

const limit = (over: Partial<SpendLimitReading> = {}): SpendLimitReading => ({
  id: 'l1', scope: 'project', scope_id: null, enforcement: 'internal_monitoring', amount: 1000,
  currency: 'SAR', period: { from: '2026-09-01', to: '2026-09-30', days: 30 }, elapsed_days: 20,
  consumed: 1200, consumed_currency: 'SAR', remaining: -200, utilisation: 1.2, pace: 1.8,
  projected_period_spend: 1800, projected_exhaustion: { date: null, reason: 'already_reached' },
  thresholds: [80], state: 'over', basis: 'comparable', ...over,
})

const one = (items: Parameters<typeof buildActionCentre>[0]) => buildFindings(buildActionCentre(items), ctx)

describe('a finding is only drawn when something measured stands behind it', () => {
  it('drops an alert whose context carries no figure', () => {
    expect(one({ alerts: [alert({ context: {} })] })).toEqual([])
  })

  it('drops a limit the governor could not read at all', () => {
    expect(one({ limits: [limit({ consumed: null, projected_period_spend: null, utilisation: null, pace: null })] })).toEqual([])
  })

  it('drops an alert type the page does not know how to evidence', () => {
    expect(one({ alerts: [alert({ type: 'something_new', context: { value: 3 } })] })).toEqual([])
  })

  it('keeps a finding that has a recorded fact even with no figure', () => {
    const [f] = one({ alerts: [alert({ type: 'token_expiry', entity_type: null, entity_id: null, context: { provider: 'meta', expires_at: '2026-09-20T00:00:00Z' } })] })
    expect(f.nature).toBe('tracking')
    expect(f.facts).toContainEqual({ key: 'expires_at', value: '2026-09-20' })
    expect(hasEvidence(f)).toBe(true)
  })
})

describe('before and current come from the engine, not from arithmetic here', () => {
  it('reads a ROAS drop as a problem with both windows, on the campaign it names', () => {
    const [f] = one({ alerts: [alert()] })
    expect(f.nature).toBe('problem')
    expect(f.subject).toMatchObject({ type: 'campaign', id: 'c1', name: 'Riyadh launch', platform: 'meta' })
    expect(f.kpis[0]).toMatchObject({ key: 'roas', before: 4, current: 2.5, kind: 'ratio' })
    expect(relativeChange(f.kpis[0])).toBeCloseTo(-0.375)
    expect(f.trend).toEqual({ campaignId: 'c1', metric: 'roas', kind: 'ratio' })
    expect(f.evidence).toEqual({ target: 'campaign', path: '/campaigns/p1/c1' })
  })

  it('draws no movement where the engine stated no before', () => {
    const [f] = one({ alerts: [alert({ type: 'no_results', context: { spend: 500, conversions: 0, days: 3 } })] })
    expect(f.kpis.every((k) => k.before === null)).toBe(true)
    expect(f.kpis.map(relativeChange)).toEqual([null, null])
  })

  /** A reported zero is a measurement: it stays 0 and is not dropped as «no evidence». */
  it('keeps a reported zero as zero', () => {
    const [f] = one({ alerts: [alert({ type: 'no_results', context: { spend: 500, conversions: 0, days: 3 } })] })
    expect(f.kpis.find((k) => k.key === 'conversions')?.current).toBe(0)
  })
})

describe('impact is stated only where it is arithmetic on stated figures', () => {
  it('states overspend in the limit’s own currency', () => {
    const [f] = one({ limits: [limit()] })
    expect(f.impact).toEqual({ kind: 'overspend', amount: 200, currency: 'SAR' })
    expect(f.consumption).toBe(1.2)
  })

  it('states no impact when the spend was read in another currency', () => {
    const [f] = one({ limits: [limit({ consumed_currency: 'USD' })] })
    expect(f.impact).toBeNull()
    expect(f.kpis.find((k) => k.key === 'spend')?.current).toBeNull()
  })

  it('states a projected overrun only from the governor’s projection', () => {
    const [f] = one({ limits: [limit({ state: 'approaching', consumed: 850, utilisation: 0.85, projected_period_spend: 1300 })] })
    expect(f.impact).toEqual({ kind: 'projected_overrun', amount: 300, currency: 'SAR' })
  })

  it('refuses spend-without-results impact when the project currency is unknown', () => {
    const noCurrency = { ...ctx, currency: null }
    const [f] = buildFindings(buildActionCentre({ alerts: [alert({ type: 'no_results', context: { spend: 500, conversions: 0, days: 3 } })] }), noCurrency)
    expect(f.impact).toBeNull()
  })

  it('never states an impact for a cost increase — that would be an estimate', () => {
    const [f] = one({ alerts: [alert({ type: 'cpa_increase', context: { cpa_previous: 20, cpa_current: 30, conversions: 40 } })] })
    expect(f.impact).toBeNull()
    expect(f.kpis[0]).toMatchObject({ key: 'cpa', before: 20, current: 30, currency: 'SAR', higherIsBetter: false })
  })
})

describe('opportunity or problem is decided by the metric and its direction', () => {
  const anomaly = (metric: string, direction: 'up' | 'down') =>
    alert({ type: 'metric_anomaly', context: { points: [{ date: '2026-09-15', metric, value: 90, baseline: 40, deviation: 5, direction }] } })

  it('reads conversions spiking as an opportunity', () => {
    expect(one({ alerts: [anomaly('conversions', 'up')] })[0].nature).toBe('opportunity')
  })

  it('reads a CPA spike as a problem and a CPA fall as an opportunity', () => {
    expect(one({ alerts: [anomaly('cpa', 'up')] })[0].nature).toBe('problem')
    expect(one({ alerts: [anomaly('cpa', 'down')] })[0].nature).toBe('opportunity')
  })

  it('refuses to call a spend spike good or bad news', () => {
    expect(one({ alerts: [anomaly('spend', 'up')] })[0].nature).toBe('risk')
  })

  it('reads a rising creative as an opportunity and names its evidence page', () => {
    const creative = { id: 'k1', name: 'Eid film', provider: 'snapchat' } as never
    const [f] = one({ rising: [{ metric: 'ctr', higher_wins: true, current: 0.03, previous: 0.02, change: 0.5, improvement: 0.5, creative }] })
    expect(f.nature).toBe('opportunity')
    expect(f.action).toBe('scale_winner')
    expect(f.evidence).toEqual({ target: 'content', path: '/content/k1' })
  })
})

describe('one fact, one card', () => {
  it('shows a spend limit once when its alert and its reading both arrive', () => {
    const limitAlert = alert({ type: 'budget_risk', entity_type: 'App\\Domains\\Metrics\\Models\\SpendLimit', entity_id: 'l1', context: { consumed: 1200, limit: 1000, currency: 'SAR', threshold: 100 } })
    const findings = one({ alerts: [limitAlert], limits: [limit()] })
    expect(findings).toHaveLength(1)
    expect(findings[0].source).toBe('budget')
  })

  it('does not repeat a fatigued creative as a decliner', () => {
    const creative = { id: 'k1', name: 'Eid film', provider: 'snapchat', fatigue: { status: 'fatigued', signals: [{ key: 'ctr', direction: 'worse', change: -0.4, current: 0.01, previous: 0.02 }], reason_ar: '', reason_en: '' } } as never
    const items = buildActionCentre({
      fatigued: [creative],
      declining: [{ metric: 'ctr', higher_wins: true, current: 0.01, previous: 0.02, change: -0.5, improvement: -0.5, creative }],
    })
    expect(items.map((i) => i.id)).toEqual(['creative:k1'])
    expect(toFinding(items[0], ctx)?.kpis[0]).toMatchObject({ key: 'ctr', before: 0.02, current: 0.01 })
  })
})
