import { QueryClient } from '@tanstack/react-query'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { listenForWorkspaceSwitch } from './workspaceSwitch'
import { useAuth } from '@/stores/auth'

/** WORKSPACE-SWITCH-001 — one session, so one workspace: a switch in one tab moves them all. */
describe('listenForWorkspaceSwitch', () => {
  const assign = vi.fn()
  const original = window.location
  let client: QueryClient
  let stop: () => void

  const signal = (value: string | null, key = 'chub:workspace-switched') =>
    window.dispatchEvent(new StorageEvent('storage', { key, newValue: value }))

  beforeEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: { ...original, assign } })
    client = new QueryClient()
    client.setQueryData(['projects'], [{ id: 'p-old' }])
    stop = listenForWorkspaceSwitch(client)
    useAuth.setState({ status: 'authenticated', user: { id: 'u' } as never })
  })

  afterEach(() => {
    stop()
    Object.defineProperty(window, 'location', { configurable: true, value: original })
    assign.mockReset()
    useAuth.setState({ status: 'loading', user: null })
  })

  it('leaves the old workspace behind when another tab switches', () => {
    signal(JSON.stringify({ destination: '/agency', at: 1 }))

    expect(assign).toHaveBeenCalledWith('/agency')
    expect(client.getQueryData(['projects'])).toBeUndefined()
  })

  it('does not follow a destination out of the app', () => {
    signal(JSON.stringify({ destination: 'https://evil.example', at: 1 }))
    expect(assign).toHaveBeenCalledWith('/switch')
  })

  it('moves nothing for a guest, a removed key, or an unrelated key', () => {
    signal(null)
    signal(JSON.stringify({ destination: '/agency' }), 'something-else')
    useAuth.setState({ status: 'guest', user: null })
    signal(JSON.stringify({ destination: '/agency', at: 1 }))

    expect(assign).not.toHaveBeenCalled()
    expect(client.getQueryData(['projects'])).toBeDefined()
  })
})
