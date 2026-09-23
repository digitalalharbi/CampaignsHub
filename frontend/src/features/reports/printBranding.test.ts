import { describe, expect, it } from 'vitest'

import { headerIdentity, printTitle, type SharedBranding } from './sharedBranding'
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
/*
 * The PRODUCT's rule — `printTitle()` — and not a copy of it. This file used to re-state the title in
 * a local function, so it could only agree with itself.
 */
const title = (branding: SharedBranding | undefined, audience: string, payload: { currency: string; report_id: string; checksum: string | null; data_version: number | null }) =>
  printTitle({ ...payload, name: 'Q3 Performance', locale: 'ar', audience }, headerIdentity(branding).name)

const PAYLOAD = { currency: 'SAR', report_id: 'r-1', checksum: 'abc', data_version: 3 }

const client: SharedBranding = { name: 'Nakheel', logo_url: null, logo_source: 'none', by: 'Al Harbi Agency' }

describe('the name a printed report carries in its metadata', () => {
  // REPORT BRANDING (Owner): a client or executive file is titled like its link — the report's name
  // ending with the product's, in the report's language. Whose report it is is on the cover.
  it('titles a client file with the report’s name and the product’s, in the report’s language', () => {
    expect(title(client, 'client', PAYLOAD)).toBe('Q3 Performance — كامبينز هب')
    expect(printTitle({ ...PAYLOAD, name: 'Q3 Performance', locale: 'en', audience: 'client' }, 'Nakheel')).toBe('Q3 Performance — CampaignsHub')
  })

  it('titles an executive file the same way', () => {
    expect(title(client, 'executive', PAYLOAD)).toBe('Q3 Performance — كامبينز هب')
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
  it('never produces an empty title, even for an unnamed report', () => {
    expect(printTitle({ ...PAYLOAD, name: '', locale: 'ar', audience: 'client' }, 'CampaignsHub')).toBe('تقرير الأداء — كامبينز هب')
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
