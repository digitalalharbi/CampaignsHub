import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { afterSessionRotation } from './client'

/**
 * WEBKIT-SESSION-ROTATION-001 — a sign-in returns once the browser holds the signed-in session.
 *
 * Observed on webkit in CI: `/auth/login` answered 200, and the `/auth/memberships` fired straight after it left with the
 * guest session the login had just replaced — 401, whose own Set-Cookie then put the guest id back over the signed-in one,
 * and the person landed on «your session ended». The login response re-sets XSRF-TOKEN beside the session cookie, so the
 * readable token changing is the moment the new session is held.
 */
const clear = () => { document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' }

describe('afterSessionRotation', () => {
  beforeEach(() => {
    clear()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
    clear()
  })

  it('does not resolve while the browser still shows the pre-sign-in token, and does once the new one lands', async () => {
    document.cookie = 'XSRF-TOKEN=guest; path=/'

    // The request settles first; the engine commits the response's cookies 60ms later — the WebKit order.
    const settled = vi.fn()
    const call = afterSessionRotation(async () => {
      setTimeout(() => { document.cookie = 'XSRF-TOKEN=signed-in; path=/' }, 60)
      return 'user'
    }).then((value) => { settled(value); return value })

    await vi.advanceTimersByTimeAsync(40)
    expect(settled).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(40)
    expect(settled).toHaveBeenCalledWith('user')
    await expect(call).resolves.toBe('user')
  })

  it('resolves at once when the cookie was already committed by the time the request settled (Chromium, Firefox)', async () => {
    document.cookie = 'XSRF-TOKEN=guest; path=/'

    const settled = vi.fn()
    void afterSessionRotation(async () => {
      document.cookie = 'XSRF-TOKEN=signed-in; path=/'
      return 1
    }).then(settled)

    await vi.advanceTimersByTimeAsync(0)
    expect(settled).toHaveBeenCalledWith(1)
  })

  it('is bounded: a response that set no cookie does not freeze the page', async () => {
    document.cookie = 'XSRF-TOKEN=guest; path=/'

    const settled = vi.fn()
    void afterSessionRotation(async () => 'answer').then(settled)

    await vi.advanceTimersByTimeAsync(900)
    expect(settled).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(200)
    expect(settled).toHaveBeenCalledWith('answer')
  })

  it('waits for nothing when there was no token to begin with', async () => {
    const settled = vi.fn()
    void afterSessionRotation(async () => 'answer').then(settled)

    await vi.advanceTimersByTimeAsync(0)
    expect(settled).toHaveBeenCalledWith('answer')
  })

  it('a refused request is refused, not waited on', async () => {
    document.cookie = 'XSRF-TOKEN=guest; path=/'

    await expect(afterSessionRotation(async () => { throw new Error('422') })).rejects.toThrow('422')
  })
})
