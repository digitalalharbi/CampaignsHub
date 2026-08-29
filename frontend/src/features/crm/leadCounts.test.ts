import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn() },
  getData: vi.fn(),
  postData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import { api } from '@/lib/api/client'
import { listLeads } from './api'

/**
 * LEAD-DEDUP-001 — the counts are REPORTED, never inferred.
 *
 * `received` and `unique` are two different facts about a list, and a client that filled either in
 * for itself would be stating something the server never said. The dangerous direction is a
 * confident zero: a page reading «0 received» on a backend that simply has not shipped the field
 * looks like an account with no leads, which is a fact about the customer's business.
 *
 * Guarded here rather than in the page test, because the page mocks this module out and cannot
 * reach the mapping at all.
 */
const envelope = (meta: Record<string, unknown>) => ({
  data: { data: [], meta },
})

describe('the lead list mapping', () => {
  beforeEach(() => vi.clearAllMocks())

  it('carries the counts the server reported', async () => {
    vi.mocked(api.get).mockResolvedValue(envelope({ counts: { received: 412, unique: 389 } }) as never)

    expect((await listLeads({})).counts).toEqual({ received: 412, unique: 389 })
  })

  it('reports NOTHING rather than zero when the server sent no counts', async () => {
    vi.mocked(api.get).mockResolvedValue(envelope({}) as never)

    expect((await listLeads({})).counts).toBeNull()
  })
})
