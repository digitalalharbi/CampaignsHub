import { describe, expect, it } from 'vitest'

import { headerIdentity, type SharedBranding } from './sharedBranding'
import { isClientAudience } from './InteractiveReport'

/**
 * BRANDING-HIERARCHY-001 in the EXPORTED file's own metadata.
 *
 * The print route set `document.title` to «CampaignsHub» for every report, and that string becomes
 * the PDF's `/Title`: it is what a client's title bar shows, and what a mail client shows beside the
 * attachment. An agency's client PDF announced the product.
 *
 * The internal arm is deliberately unchanged. `rid`/`checksum`/`data_version` are what make an
 * internal snapshot auditable, and they must stay OUT of client and executive files — a title is
 * metadata that travels with the document to wherever the client forwards it.
 */
const title = (branding: SharedBranding | undefined, audience: string, payload: { currency: string; report_id: string; checksum: string | null; data_version: number | null }) => {
  const who = headerIdentity(branding).name

  /*
   * The PRODUCT's predicate, not a copy of it.
   *
   * This test re-stated the audience rule inline, so it could only ever agree with itself: the file
   * it guards had a second copy of the same rule that answered differently for the executive
   * audience, and this test passed throughout. A test that re-implements what it is checking is a
   * test of the test.
   */
  return isClientAudience(audience)
    ? `${who} — ${payload.currency} Report`
    : `${who} | rid=${payload.report_id} | cs=${payload.checksum ?? ''} | dv=${payload.data_version ?? ''} | cur=${payload.currency}`
}

const PAYLOAD = { currency: 'SAR', report_id: 'r-1', checksum: 'abc', data_version: 3 }

const client: SharedBranding = { name: 'Nakheel', logo_url: null, logo_source: 'none', by: 'Al Harbi Agency' }

describe('the name a printed report carries in its metadata', () => {
  it('names the client on a client file, not the product', () => {
    expect(title(client, 'client', PAYLOAD)).toBe('Nakheel — SAR Report')
  })

  it('names the client on an executive file too', () => {
    expect(title(client, 'executive', PAYLOAD)).toContain('Nakheel')
  })

  /* Client and executive files must not carry internal identifiers — that rule is untouched. */
  it('keeps internal provenance out of a client file', () => {
    const t = title(client, 'client', PAYLOAD)

    expect(t).not.toContain('rid=')
    expect(t).not.toContain('cs=')
    expect(t).not.toContain('dv=')
  })

  /* …and an internal file keeps every bit of it, because that is what makes it auditable. */
  it('keeps internal provenance on an internal file', () => {
    const t = title(client, 'internal', PAYLOAD)

    expect(t).toContain('rid=r-1')
    expect(t).toContain('cs=abc')
    expect(t).toContain('dv=3')
  })

  /* With nothing resolved, the product's name — never an empty title. */
  it('falls back to the product rather than to an empty title', () => {
    expect(title(undefined, 'client', PAYLOAD)).toBe('CampaignsHub — SAR Report')
  })

  /**
   * The METADATA and the PAGE have to agree about who is reading.
   *
   * The title withheld `rid`/`checksum`/`data_version` from the executive file — a client document —
   * while the methodology page printed all three, plus `daily_metrics` and the attribution window,
   * because it asked only whether the audience was literally `client`. One document, both statements,
   * and the visible one was the wrong one.
   */
  it('withholds the provenance line from every client-facing audience', () => {
    for (const audience of ['client', 'executive']) {
      expect(isClientAudience(audience), `${audience} is a client-facing file`).toBe(true)
    }

    expect(isClientAudience('internal'), 'an internal file stays auditable').toBe(false)
  })
})
