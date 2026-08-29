import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn() },
  getData: vi.fn(),
  postData: vi.fn(),
  putData: vi.fn(),
  patchData: vi.fn(),
  deleteData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import { api } from '@/lib/api/client'
import { listCampaigns } from './api'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — «the list stopped» is REPORTED, never assumed.
 *
 * Three states, not two: the server said it stopped, the server said it did not, and the server did
 * not say. Collapsing the third into «complete» is the client making a promise on the server's
 * behalf — and it is the state every deployment is in until the backend half ships.
 *
 * Guarded here rather than in the page test, because the page renders on `=== true` and cannot tell
 * `false` from `null` at all; an injected `null → false` passed every page assertion.
 */
const envelope = (meta: Record<string, unknown>) => ({ data: { data: [], meta } })

describe('the campaign list page shape', () => {
  beforeEach(() => vi.clearAllMocks())

  it('carries the truncation the server reported', async () => {
    vi.mocked(api.get).mockResolvedValue(envelope({ truncated: true, limit: 500 }) as never)

    const page = await listCampaigns('p1')

    expect(page.truncated).toBe(true)
    expect(page.limit).toBe(500)
  })

  it('carries a reported completeness as completeness', async () => {
    vi.mocked(api.get).mockResolvedValue(envelope({ truncated: false, limit: 500 }) as never)

    expect((await listCampaigns('p1')).truncated).toBe(false)
  })

  it('reports NOTHING when the server said nothing', async () => {
    vi.mocked(api.get).mockResolvedValue(envelope({}) as never)

    const page = await listCampaigns('p1')

    expect(page.truncated).toBeNull()
    expect(page.limit).toBeNull()
  })
})
