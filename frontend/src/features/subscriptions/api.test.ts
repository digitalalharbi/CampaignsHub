import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  getData: vi.fn(),
  postData: vi.fn(),
}))

import { getData, postData } from '@/lib/api/client'
import { changePlan, getCurrent, getPlans } from './api'

const mockGet = vi.mocked(getData)
const mockPost = vi.mocked(postData)

describe('subscriptions api layer', () => {
  beforeEach(() => vi.clearAllMocks())

  it('reads the plan catalogue', async () => {
    mockGet.mockResolvedValue([{ code: 'starter' }, { code: 'growth' }])
    const out = await getPlans()
    expect(getData).toHaveBeenCalledWith('/subscriptions/plans')
    expect(out).toEqual([{ code: 'starter' }, { code: 'growth' }])
  })

  it('reads the current subscription + usage', async () => {
    const payload = {
      subscription: { status: 'active', seats: 3, current_period_end: null },
      plan: { code: 'growth', name: 'Growth', price_monthly: '99', currency: 'USD', features: {}, limits: {} },
      is_default_plan: false,
      usage: { projects: { limit: 25, used: 4, remaining: 21 } },
    }
    mockGet.mockResolvedValue(payload)
    expect(await getCurrent()).toEqual(payload)
    expect(getData).toHaveBeenCalledWith('/subscriptions/current')
  })

  it('changes plan by its code (plan_code body)', async () => {
    mockPost.mockResolvedValue({ subscription: { status: 'active', seats: 1, current_period_end: null }, plan: { code: 'scale' } })
    const out = await changePlan('scale')
    expect(postData).toHaveBeenCalledWith('/subscriptions/change', { plan_code: 'scale' })
    expect(out.plan.code).toBe('scale')
  })
})
