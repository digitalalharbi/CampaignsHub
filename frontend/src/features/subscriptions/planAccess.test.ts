import { describe, expect, it } from 'vitest'
import type { SubscriptionPlan } from './api'
import { featureKeys, includes, ladder, limitKeys, planWith, thresholdOf, worstThreshold } from './planAccess'

const plan = (code: string, price: string, features: SubscriptionPlan['features'], limits: SubscriptionPlan['limits'] = {}): SubscriptionPlan => ({
  code, name: code, price_monthly: price, currency: 'SAR', features, limits,
})

describe('thresholds', () => {
  it('follow the Owner’s 75 / 90 / 100 points, and an unmetered limit never alarms', () => {
    expect(thresholdOf({ limit: 100, used: 74, remaining: 26 })).toBe('ok')
    expect(thresholdOf({ limit: 100, used: 75, remaining: 25 })).toBe('near')
    expect(thresholdOf({ limit: 100, used: 90, remaining: 10 })).toBe('high')
    expect(thresholdOf({ limit: 100, used: 100, remaining: 0 })).toBe('full')
    expect(thresholdOf({ limit: 100, used: 140, remaining: 0 })).toBe('full')
    expect(thresholdOf({ limit: null, used: 9000, remaining: null })).toBe('unlimited')
  })

  it('reports the worst one reached', () => {
    expect(worstThreshold({ a: { limit: 10, used: 1, remaining: 9 }, b: { limit: 10, used: 9, remaining: 1 } })).toBe('high')
    expect(worstThreshold({})).toBe('unlimited')
  })
})

describe('plans', () => {
  const solo = plan('solo', '99', { alerts: true, team: false, support: 'email' }, { clients: 1 })
  const team = plan('team', '299', { alerts: true, team: true, support: 'priority' }, { clients: 5 })
  const agency = plan('agency', '999', { alerts: true, team: true, white_label: true, support: 'priority' }, { clients: null })

  it('orders cheapest first, by the price the console sets', () => {
    expect(ladder([agency, solo, team]).map((p) => p.code)).toEqual(['solo', 'team', 'agency'])
  })

  it('names the cheapest plan that includes a feature', () => {
    expect(planWith([agency, solo, team], 'team')?.code).toBe('team')
    expect(planWith([agency, solo, team], 'white_label')?.code).toBe('agency')
    expect(planWith([solo, team], 'white_label')).toBeNull()
  })

  it('reads false, empty, none and absent as not included', () => {
    expect(includes(solo, 'team')).toBe(false)
    expect(includes(solo, 'white_label')).toBe(false)
    expect(includes(solo, 'support')).toBe(true)
    expect(includes(plan('x', '1', { support: 'none' }), 'support')).toBe(false)
  })

  it('lists every feature and limit any plan names, once', () => {
    expect(featureKeys([solo, team, agency]).sort()).toEqual(['alerts', 'support', 'team', 'white_label'])
    expect(limitKeys([solo, team, agency])).toEqual(['clients'])
  })
})
