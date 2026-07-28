import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api/client', () => ({
  api: { get: vi.fn() },
  getData: vi.fn(),
  postData: vi.fn(),
  ensureCsrfCookie: vi.fn().mockResolvedValue(undefined),
}))

import { api, ensureCsrfCookie, getData, postData } from '@/lib/api/client'
import { CONNECTION_STATES, getConnectionHistory, listConnectors, syncConnector } from './api'

const mockGet = vi.mocked(api.get)

describe('connections api layer — project-scoped', () => {
  beforeEach(() => vi.clearAllMocks())

  it('exposes exactly the seven honest states', () => {
    expect(CONNECTION_STATES).toEqual([
      'available', 'awaiting_credentials', 'sandbox_verified', 'production_verified',
      'permission_missing', 'token_expired', 'sync_failed',
    ])
  })

  it('lists connectors from the project-scoped endpoint and unwraps the envelope', async () => {
    mockGet.mockResolvedValue({ data: { data: [{ provider: 'sandbox', state: 'sandbox_verified' }] } })
    const out = await listConnectors('p1')
    expect(api.get).toHaveBeenCalledWith('/projects/p1/connections')
    expect(out).toEqual([{ provider: 'sandbox', state: 'sandbox_verified' }])
  })

  it('preserves an honest awaiting-credentials sync result (no fabricated success)', async () => {
    vi.mocked(postData).mockResolvedValue({ provider: 'meta_ads', state: 'awaiting_credentials', status: 'failed', records: 0, metrics_upserted: 0 })
    const out = await syncConnector('p1', 'meta_ads')
    expect(ensureCsrfCookie).toHaveBeenCalled()
    expect(postData).toHaveBeenCalledWith('/projects/p1/connections/meta_ads/sync', { days: 7 })
    expect(out.state).toBe('awaiting_credentials')
    expect(out.status).toBe('failed')
  })

  it('encodes the provider and passes a custom window on sync', async () => {
    vi.mocked(postData).mockResolvedValue({ provider: 'a b', state: 'sandbox_verified', status: 'success' })
    await syncConnector('p1', 'a b', 30)
    expect(postData).toHaveBeenCalledWith('/projects/p1/connections/a%20b/sync', { days: 30 })
  })

  it('reads connection history from the history endpoint', async () => {
    vi.mocked(getData).mockResolvedValue({ provider: 'sandbox', runs: [], errors: [], data_freshness: {} })
    await getConnectionHistory('p1', 'sandbox')
    expect(getData).toHaveBeenCalledWith('/projects/p1/connections/sandbox/history')
  })
})
