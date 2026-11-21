import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render } from '@testing-library/react'
import { Providers, queryClient } from './providers'

vi.mock('@/features/auth/api', () => ({ probeSession: vi.fn(() => new Promise(() => {})), isDefinitelyGuest: vi.fn(() => false) }))
vi.mock('@/features/auth/memberships', async (orig) => ({
  ...(await orig<typeof import('@/features/auth/memberships')>()),
  fetchMemberships: vi.fn(() => new Promise(() => {})),
}))
vi.mock('@/features/bootstrap/bootstrap', () => ({ bootSession: vi.fn(() => new Promise(() => {})) }))

import { probeSession } from '@/features/auth/api'
import { fetchMemberships } from '@/features/auth/memberships'
import { bootSession } from '@/features/bootstrap/bootstrap'

/**
 * PERF-INSTANT-DATA-001 §12.2.5 — no chained loading after the page opens.
 *
 * Measured locally (400ms per request): `/auth/me` answered, THEN `/auth/memberships` was asked, THEN the
 * page's own requests went out — three round trips in a row before the first KPI. The membership list
 * does not depend on the probe's answer (both read the same session), so on an authenticated surface it
 * is asked at the same moment, under the key the portal guards already read.
 *
 * PERF-BOOTSTRAP-001 — that was two requests; it is now ONE. On an authenticated surface the probe is the bootstrap, which answers
 * who you are AND carries the memberships (and five more answers), so neither `/auth/me` nor `/auth/memberships` is sent beside it.
 * The fallback to the old pair is `bootstrap.test.tsx`'s.
 */
function bootAt(path: string) {
  window.history.pushState({}, '', path)
  render(<Providers><span /></Providers>)
}

describe('the boot sequence', () => {
  beforeEach(() => { vi.clearAllMocks(); queryClient.clear() })
  afterEach(() => cleanup())

  it.each(['/agency/analytics', '/app/dashboard', '/influencers/home', '/portal'])(
    'asks once, for the bootstrap, on %s — no separate session probe, no separate memberships',
    (path) => {
      bootAt(path)
      expect(bootSession).toHaveBeenCalledTimes(1)
      expect(probeSession).not.toHaveBeenCalled()
      expect(fetchMemberships).not.toHaveBeenCalled()
    },
  )

  it.each(['/login', '/portal/login', '/r/abc123', '/reports/share/abc123', '/reports/print/abc123', '/data-deletion', '/'])(
    'does not ask for memberships or the bootstrap on %s, where there is no session to list them for',
    (path) => {
      bootAt(path)
      expect(fetchMemberships).not.toHaveBeenCalled()
      expect(bootSession).not.toHaveBeenCalled()
    },
  )
})
