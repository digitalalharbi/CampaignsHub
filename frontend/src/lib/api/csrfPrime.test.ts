import axios from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ensureCsrfCookie } from './client'

/**
 * WEBKIT-CSRF-RACE-002 — the sign-in flow must never open a second session by priming a token it already holds.
 *
 * Observed: the first sign-in of a CI run on WebKit answered 419 in under a second. The lookup had primed and the login
 * primed again before the first session cookie had committed, so the login POST carried the token of a session the browser
 * no longer held. A readable token is a current one; it is used, not re-fetched; and concurrent primes share one request.
 */
describe('ensureCsrfCookie', () => {
  let get: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
    get = vi.spyOn(axios, 'get').mockImplementation(async () => {
      document.cookie = 'XSRF-TOKEN=primed; path=/'
      return { data: '' } as never
    })
  })

  afterEach(() => {
    get.mockRestore()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
  })

  it('does not prime again while a token is readable', async () => {
    document.cookie = 'XSRF-TOKEN=already; path=/'

    await ensureCsrfCookie()

    expect(get).not.toHaveBeenCalled()
  })

  it('primes once when the token is absent, and a second caller in the same tick shares that request', async () => {
    await Promise.all([ensureCsrfCookie(), ensureCsrfCookie()])

    expect(get).toHaveBeenCalledTimes(1)
    expect(document.cookie).toContain('XSRF-TOKEN=primed')
  })

  it('a prime after the first has settled is not needed: the token it set is read, not re-fetched', async () => {
    await ensureCsrfCookie()
    await ensureCsrfCookie()

    expect(get).toHaveBeenCalledTimes(1)
  })
})
