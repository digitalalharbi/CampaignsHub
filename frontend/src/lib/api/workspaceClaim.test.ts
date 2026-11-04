import { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { claimMembership } from './workspaceClaim'

/** ENSURE-PORTAL-SESSION-001 — every request says which workspace its screen is for; a refusal takes the tab away. */
describe('the workspace claim on the wire', () => {
  const assign = vi.fn()
  const original = window.location
  const adapter = vi.fn()

  const send = (status = 200, data: unknown = {}) => {
    adapter.mockImplementation(async (config: InternalAxiosRequestConfig) => {
      const response = { data, status, statusText: '', headers: {}, config }
      if (status >= 400) throw new AxiosError('refused', 'ERR', config, null, response)
      return response
    })
  }

  beforeEach(() => {
    vi.clearAllMocks()
    Object.defineProperty(window, 'location', { configurable: true, value: { ...original, assign } })
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: original })
  })

  const request = (config: AxiosRequestConfig) => api.request({ ...config, adapter })
  const sentHeader = () => (adapter.mock.calls[0][0] as InternalAxiosRequestConfig).headers.get('X-Workspace-Membership')

  it('is sent on reads and writes alike once the tab has one', async () => {
    send()
    claimMembership('m-app')
    await request({ url: '/projects', method: 'get' })
    expect(sentHeader()).toBe('m-app')

    adapter.mockClear()
    await request({ url: '/projects/1', method: 'patch', data: {} })
    expect(sentHeader()).toBe('m-app')
  })

  it('is absent before the tab has one', async () => {
    send()
    await request({ url: '/auth/memberships', method: 'get' })
    expect(sentHeader()).toBeFalsy()
  })

  it('a refused claim leaves for the chooser, and says why', async () => {
    send(409, { code: 'workspace_changed' })
    claimMembership('m-app')
    await expect(request({ url: '/projects', method: 'get' })).rejects.toBeDefined()
    expect(assign).toHaveBeenCalledWith('/switch?reason=workspace_changed')
  })

  it('any other 409 is the caller\'s to handle', async () => {
    send(409, { code: 'something_else' })
    await expect(request({ url: '/projects', method: 'post' })).rejects.toBeDefined()
    expect(assign).not.toHaveBeenCalled()
  })
})
