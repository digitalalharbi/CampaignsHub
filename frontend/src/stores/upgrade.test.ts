import { describe, expect, it } from 'vitest'
import { upgradeRefusalFrom } from './upgrade'

/**
 * PAY-AUDIT-004 — which 403s are a commercial conversation, and which are none of this dialog's
 * business.
 *
 * The strictness is the point. Most 403s in this product are PERMISSION refusals — a colleague has
 * not granted a role, a client space is not this operator's to open — and answering those with «your
 * plan does not allow this, here are the plans» would be worse than saying nothing: it invites
 * somebody to spend money on a problem money does not fix.
 */
describe('upgradeRefusalFrom', () => {
  const base = { message: 'refused', status: 403, meta: null as Record<string, unknown> | null }

  it('reads a plan-limit refusal, numbers and all', () => {
    const refusal = upgradeRefusalFrom({
      ...base,
      message: 'لقد بلغت الحد الأقصى من المشاريع (3 من 3).',
      meta: { plan_limit: true, metric: 'projects', used: 3, limit: 3, upgrade_path: '/app/subscriptions' },
    })

    expect(refusal).not.toBeNull()
    expect(refusal?.reason).toBe('plan_limit')
    expect(refusal?.subject).toBe('projects')
    expect(refusal?.used).toBe(3)
    expect(refusal?.limit).toBe(3)
    expect(refusal?.upgradePath).toBe('/app/subscriptions')
  })

  it('reads an entitlement refusal, which carries no numbers', () => {
    const refusal = upgradeRefusalFrom({
      ...base,
      meta: { entitlement: true, capability: 'clients', plan: 'starter', upgrade_path: '/app/subscriptions' },
    })

    expect(refusal?.reason).toBe('entitlement')
    expect(refusal?.subject).toBe('clients')
    expect(refusal?.plan).toBe('starter')
    // A section refusal has no usage to report, and inventing a zero would read as «0 of 0».
    expect(refusal?.used).toBeNull()
    expect(refusal?.limit).toBeNull()
  })

  it('ignores an ordinary permission refusal', () => {
    expect(upgradeRefusalFrom({ ...base, meta: { request_id: 'abc' } })).toBeNull()
    expect(upgradeRefusalFrom({ ...base, meta: null })).toBeNull()
  })

  it('ignores a refusal that is not a 403 at all', () => {
    expect(upgradeRefusalFrom({
      ...base,
      status: 422,
      meta: { plan_limit: true, metric: 'projects', used: 3, limit: 3 },
    })).toBeNull()
  })

  it('falls back to the subscriptions page when the server names no path', () => {
    const refusal = upgradeRefusalFrom({ ...base, meta: { entitlement: true, capability: 'clients' } })

    expect(refusal?.upgradePath).toBe('/app/subscriptions')
  })
})
