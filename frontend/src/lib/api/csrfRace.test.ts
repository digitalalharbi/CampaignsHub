import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('axios', () => ({ default: { get: vi.fn().mockResolvedValue({}), create: vi.fn(() => ({ interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } }, defaults: {} })) } }))

/**
 * WEBKIT-CSRF-RACE-001 — the cookie being SET and the cookie being READABLE are two events.
 *
 * Axios builds the next POST by reading `document.cookie` synchronously. WebKit commits a
 * `Set-Cookie` slightly after the response settles, so a form that had correctly primed its token
 * still posted without one and got 419. It looked like flake for a day.
 */
describe('ensureCsrfCookie', () => {
  beforeEach(() => {
    vi.useRealTimers()
    Object.defineProperty(document, 'cookie', { writable: true, configurable: true, value: '' })
  })

  it('does not resolve until the token is actually readable', async () => {
    const { ensureCsrfCookie } = await import('./client')

    let resolved = false
    const promise = ensureCsrfCookie().then(() => { resolved = true })

    // The response has settled, but WebKit has not committed the cookie yet.
    await new Promise((r) => setTimeout(r, 30))
    expect(resolved).toBe(false)

    // The browser commits it.
    ;(document as unknown as { cookie: string }).cookie = 'XSRF-TOKEN=abc123'
    await promise

    expect(resolved).toBe(true)
  })

  it('returns immediately when the cookie is already there', async () => {
    ;(document as unknown as { cookie: string }).cookie = 'XSRF-TOKEN=abc123'
    const { ensureCsrfCookie } = await import('./client')

    const started = Date.now()
    await ensureCsrfCookie()

    // Chromium and Firefox never had the problem; they must not pay for the fix.
    expect(Date.now() - started).toBeLessThan(60)
  })

  it('gives up rather than hanging when no token ever arrives', async () => {
    const { ensureCsrfCookie } = await import('./client')

    const started = Date.now()
    await ensureCsrfCookie()
    const waited = Date.now() - started

    // Bounded: a genuinely absent token fails fast and loudly at the POST, not by freezing the form.
    expect(waited).toBeGreaterThanOrEqual(400)
    expect(waited).toBeLessThan(1500)
  })
})
