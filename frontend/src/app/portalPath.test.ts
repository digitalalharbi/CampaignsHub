import { describe, expect, it } from 'vitest'
import { portalBaseOf, portalKeyOf } from './portalPath'

/**
 * Shared operational pages are mounted under more than one portal. If a link written inside one of
 * them resolved to the wrong base, an agency operator following it would land in the advertiser
 * portal — the exact "one system wearing four hats" outcome ADR 0002 rules out.
 */
describe('portalBaseOf', () => {
  it.each([
    ['/agency/clients', '/agency'],
    ['/agency/clients/9f2', '/agency'],
    ['/agency', '/agency'],
    ['/app/clients', '/app'],
    ['/influencers/campaigns', '/influencers'],
    ['/portal/clients/acme', '/portal'],
  ])('resolves %s to %s', (path, base) => {
    expect(portalBaseOf(path)).toBe(base)
  })

  /** A path that merely STARTS with the letters is not that portal — `/application` is not `/app`. */
  it('does not treat a longer segment as a portal prefix', () => {
    expect(portalBaseOf('/applications')).toBe('/app')
    expect(portalKeyOf('/applications')).toBe('app')
  })

  /** Anything outside a portal falls back to the advertiser portal, the historic home of these pages. */
  it('falls back to the advertiser portal for non-portal paths', () => {
    expect(portalBaseOf('/switch')).toBe('/app')
    expect(portalBaseOf('/')).toBe('/app')
  })
})
