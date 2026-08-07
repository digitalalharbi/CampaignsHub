import { describe, expect, it } from 'vitest'
import { isSessionlessSurface } from './providers'

/**
 * PUBLIC-REPORT-NOAUTH — which addresses are not allowed to ask about a session.
 *
 * The app probed `GET /auth/me` on every load, including `/r/<token>`, where the reader has no
 * account and the request can only ever be answered 401 — twice per load, on the page an agency
 * sends to a paying client. Harmless while nothing renders a 401; one release away from «انتهت
 * جلستك» on a report belonging to somebody who was never signed in.
 *
 * The second half of this test matters as much as the first. The fix is a list, and a list is
 * exactly the thing somebody widens later: making the whole public site sessionless would silently
 * break the homepage's «back to your dashboard» for a visitor who IS signed in.
 */
describe('the surfaces that never ask who you are', () => {
  it('covers the client link, the legacy link and the print route', () => {
    expect(isSessionlessSurface('/r/demo-live-report-token')).toBe(true)
    expect(isSessionlessSurface('/reports/share/demo-live-report-token')).toBe(true)
    expect(isSessionlessSurface('/reports/print/some-token')).toBe(true)
  })

  it('leaves every surface that legitimately wants to know', () => {
    for (const path of ['/', '/login', '/welcome', '/register', '/app/dashboard', '/agency/clients', '/admin', '/portal/requests', '/reports', '/signup/status']) {
      expect(isSessionlessSurface(path), `${path} must still restore its session`).toBe(false)
    }
  })
})
