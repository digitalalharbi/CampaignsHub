import { describe, expect, it } from 'vitest'

import { headerIdentity, type SharedBranding } from './sharedBranding'

/**
 * BRANDING-HIERARCHY-001 — whose name and mark a client sees on their own report.
 *
 * The header rendered `report.branding.name ?? 'CampaignsHub'` and no logo at all. A report whose
 * stored config carried no branding therefore put the PRODUCT's name on an agency's client report —
 * the «never a blank header» clause, failing on the one surface read by somebody with no session and
 * nobody to ask.
 *
 * The resolution itself belongs to the backend, which alone knows the tenant. This is only what the
 * header does with the answer, and the rules it must not get wrong.
 */
const b = (o: Partial<SharedBranding> = {}): SharedBranding => ({
  name: 'Nakheel',
  logo_url: null,
  logo_source: 'none',
  by: 'Al Harbi Agency',
  ...o,
})

describe('the identity a shared report header shows', () => {
  it('shows the client’s own name and mark', () => {
    const id = headerIdentity(b({ logo_url: '/api/v1/reports/shared/tok/branding/logo', logo_source: 'client' }))

    expect(id.name).toBe('Nakheel')
    expect(id.logoUrl).toBe('/api/v1/reports/shared/tok/branding/logo')
  })

  /*
   * «بواسطة» is SECONDARY. The agency may be named on a client's report, never in place of the
   * client — and never at all when the two are the same identity, because «Nakheel, by Nakheel»
   * reads as a bug rather than as provenance.
   */
  it('names the agency secondarily, and not when it is the same identity', () => {
    expect(headerIdentity(b()).by).toBe('Al Harbi Agency')
    expect(headerIdentity(b({ by: 'Nakheel' })).by).toBeNull()
    expect(headerIdentity(b({ by: null })).by).toBeNull()
  })

  /*
   * A missing logo is «no logo», not a broken one. An <img> pointed at a URL that 404s puts a broken
   * icon in a client's report, which looks like the report itself failed — worse than no mark.
   */
  it('renders no image at all rather than one that will not load', () => {
    expect(headerIdentity(b({ logo_url: null })).logoUrl).toBeNull()
    expect(headerIdentity(b({ logo_url: '' })).logoUrl).toBeNull()
  })

  /*
   * And with nothing resolved at all, the product's own name — never an empty header. A header with
   * no text is indistinguishable from a page that failed to load.
   */
  it('never renders an empty header', () => {
    expect(headerIdentity(b({ name: '' })).name).toBe('CampaignsHub')
    expect(headerIdentity(undefined).name).toBe('CampaignsHub')
    expect(headerIdentity(undefined).logoUrl).toBeNull()
  })
})
