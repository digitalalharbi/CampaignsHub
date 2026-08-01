import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  getData: vi.fn(),
  postData: vi.fn(),
  deleteData: vi.fn(),
}))

import { deleteData, getData, postData } from '@/lib/api/client'
import { cancelPlanChange, getCurrent, getPlans, quotePlanChange, requestPlanChange } from './api'

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

  /**
   * A quote is a read that happens to be a POST — it must never reach the endpoint that commits.
   *
   * The two paths differ by one URL segment, and sending a quote to `/plan-change` would open a real
   * charge every time the customer looked at a price.
   */
  it('asks what a change would cost without committing to it', async () => {
    mockPost.mockResolvedValue({ quote: { due_now: '133.33' } })
    await quotePlanChange('scale', 'monthly')
    expect(postData).toHaveBeenCalledWith('/subscriptions/plan-change/quote', {
      plan_code: 'scale', billing_interval: 'monthly',
    })
  })

  it('commits a change against the plan-change endpoint, never the ops assignment', async () => {
    mockPost.mockResolvedValue({ quote: {}, payment: null, plan: 'growth', scheduled_plan: 'scale' })
    await requestPlanChange('scale', 'annual')
    expect(postData).toHaveBeenCalledWith('/subscriptions/plan-change', {
      plan_code: 'scale', billing_interval: 'annual',
    })
    // `/subscriptions/change` is the platform owner's free grant. A customer must never reach it.
    expect(postData).not.toHaveBeenCalledWith('/subscriptions/change', expect.anything())
  })

  it('withdraws a pending change', async () => {
    vi.mocked(deleteData).mockResolvedValue({ scheduled_plan: null })
    await cancelPlanChange()
    expect(deleteData).toHaveBeenCalledWith('/subscriptions/plan-change')
  })
})
