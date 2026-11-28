import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  api: { post: vi.fn() },
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
  getData: vi.fn(),
  postData: vi.fn().mockResolvedValue({ id: 'plan1' }),
  putData: vi.fn().mockResolvedValue({ id: 'plan1' }),
}))

import { postData, putData } from '@/lib/api/client'
import { savePlan, type LaunchSpec } from './api'

const spec = (over: Partial<LaunchSpec> = {}): LaunchSpec => ({
  name: 'Test', objective: '', objectives: { meta: 'OUTCOME_TRAFFIC' }, accounts: [{ external_account_id: 'a1', daily_budget: null }],
  ad_set: null, ad: null, tracking: { url: '' }, launch: false, ...over,
} as LaunchSpec)

describe('savePlan — LAUNCH-OBJECTIVE-PROPOSAL-001', () => {
  beforeEach(() => { vi.mocked(postData).mockClear(); vi.mocked(putData).mockClear() })

  it('sends the platform objective as the plan objective when only a proposal was applied', async () => {
    await savePlan('p1', spec())
    expect(postData).toHaveBeenCalledWith('/projects/p1/launch/plans', expect.objectContaining({ objective: 'OUTCOME_TRAFFIC' }))
  })

  it('keeps an objective that was already chosen', async () => {
    await savePlan('p1', spec({ objective: 'OUTCOME_SALES' }), 'plan1')
    expect(putData).toHaveBeenCalledWith('/projects/p1/launch/plans/plan1', expect.objectContaining({ objective: 'OUTCOME_SALES' }))
  })
})
