import { afterEach, describe, expect, it, vi } from 'vitest'
import { resolvePostAuthDestination } from './postAuthDestination'
import { portalKeyFor } from './memberships'
import { useAuth } from '@/stores/auth'

vi.mock('./memberships', async (orig) => {
  const actual = await (orig() as Promise<Record<string, unknown>>)
  return { ...actual, fetchMemberships: vi.fn() }
})

import { fetchMemberships } from './memberships'

/**
 * ADR 0002 — where a user lands after authenticating.
 *
 * The rule under test is that the browser does NOT decide: every destination is the one the server answered.
 */
describe('resolvePostAuthDestination', () => {
  afterEach(() => vi.clearAllMocks())

  const state = (destination: string) => ({
    memberships: [], current: null, destination, needs_switcher: false, requested_portal: null, requested_portal_held: null,
  })

  it('uses the server destination when there is no explicit redirect', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state('/agency') as never)
    expect(await resolvePostAuthDestination(new URLSearchParams())).toBe('/agency')
  })

  it('passes the requested portal through as a preference', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state('/influencers') as never)
    await resolvePostAuthDestination(new URLSearchParams(), 'influencers')
    expect(fetchMemberships).toHaveBeenCalledWith('influencers', undefined)
  })

  /** LOGIN-SINGLE-DOOR-001 — the redirect is asked of the server, and what comes back is what is followed. */
  it('asks the server about the redirect and goes where it answers, even when that is elsewhere', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state('/app/dashboard') as never)
    const to = await resolvePostAuthDestination(new URLSearchParams('redirect=%2Fagency%2Fclients'))
    expect(fetchMemberships).toHaveBeenCalledWith(undefined, { intended: '/agency/clients' })
    expect(to).toBe('/app/dashboard')
  })

  it('does not ask about an address that could never be honoured', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state('/app/dashboard') as never)
    for (const bad of ['https%3A%2F%2Fevil.example', '%2F%2Fevil.example', '%2Fportal%2Flogin', '%2Flogin']) {
      await resolvePostAuthDestination(new URLSearchParams(`redirect=${bad}`))
      expect(fetchMemberships).toHaveBeenLastCalledWith(undefined, undefined)
    }
  })

  /** An off-site redirect must never win; it falls through to the membership-derived destination. */
  it('refuses an off-site redirect and falls back to the server destination', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state('/app/dashboard') as never)
    const to = await resolvePostAuthDestination(new URLSearchParams('redirect=https%3A%2F%2Fevil.example'))
    expect(to).toBe('/app/dashboard')
  })

  it('refuses a protocol-relative redirect', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state('/app/dashboard') as never)
    const to = await resolvePostAuthDestination(new URLSearchParams('redirect=%2F%2Fevil.example'))
    expect(to).toBe('/app/dashboard')
  })

  /*
   * WORKSPACE-SWITCH-001 — state-based, and never a loop: one second look, then a page that can explain itself.
   */
  const http = (status: number) => Object.assign(new Error('http'), { response: { status, data: '' }, request: {} })

  it('sends an ended session to sign-in, with the way back, and stops believing it is signed in', async () => {
    useAuth.getState().setUser({ id: 'u' } as never)
    vi.mocked(fetchMemberships).mockRejectedValue(http(401))

    expect(await resolvePostAuthDestination(new URLSearchParams())).toBe('/login?reason=session&redirect=%2Fswitch')
    expect(useAuth.getState().status).toBe('guest')
    expect(fetchMemberships).toHaveBeenCalledTimes(2)
  })

  it('recovers when the second look answers: a single 5xx is not a conclusion', async () => {
    vi.mocked(fetchMemberships).mockRejectedValueOnce(http(502)).mockResolvedValueOnce(state('/agency') as never)
    expect(await resolvePostAuthDestination(new URLSearchParams())).toBe('/agency')
  })

  it('takes a persistent 5xx to the switcher, which explains it, after exactly one retry', async () => {
    vi.mocked(fetchMemberships).mockRejectedValue(http(503))
    expect(await resolvePostAuthDestination(new URLSearchParams())).toBe('/switch')
    expect(fetchMemberships).toHaveBeenCalledTimes(2)
  })

  it('does not ask a refusal twice', async () => {
    vi.mocked(fetchMemberships).mockRejectedValue(http(403))
    expect(await resolvePostAuthDestination(new URLSearchParams())).toBe('/switch')
    expect(fetchMemberships).toHaveBeenCalledTimes(1)
  })

  it('follows the server to an applicant\'s own registration status', async () => {
    vi.mocked(fetchMemberships).mockResolvedValue(state('/signup/status?request=r-1') as never)
    expect(await resolvePostAuthDestination(new URLSearchParams())).toBe('/signup/status?request=r-1')
  })

  /** If memberships cannot be read we go to the neutral switcher, never a guessed portal. */
  it('falls back to the switcher rather than guessing a portal', async () => {
    vi.mocked(fetchMemberships).mockRejectedValue(new Error('network'))
    expect(await resolvePostAuthDestination(new URLSearchParams())).toBe('/switch')
  })
})

/**
 * The auth pages and the system name the portals differently. A mismatch here would send a visitor to
 * the wrong portal silently, so the mapping is asserted rather than assumed.
 */
describe('portalKeyFor', () => {
  it('maps every auth-page portal to its system key', () => {
    expect(portalKeyFor('default')).toBe('app')
    expect(portalKeyFor('agency')).toBe('agency')
    expect(portalKeyFor('influencer')).toBe('influencers')
    expect(portalKeyFor('client')).toBe('portal')
  })
})
