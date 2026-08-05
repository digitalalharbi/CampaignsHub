/**
 * LEGAL-001 — the shared shape and constant behind every public policy page.
 *
 * Its own module because the content is split across two files and both need these. Left inside
 * either one, the import would be circular: whichever file evaluated first would read the other's
 * `CONTACT_EMAIL` while that module's body had not run yet, and every document interpolating it
 * would throw at module-evaluation time. TypeScript does not catch that — the types resolve
 * perfectly well — so it would have surfaced as a blank public site the first time anyone opened it.
 */

export const CONTACT_EMAIL = 'info@CampaignsHub.io'

export interface LegalSection {
  heading: string
  /** Paragraphs and/or bullet lists, in reading order. */
  body?: string[]
  bullets?: string[]
}

export interface LegalDoc {
  slug: string
  title: string
  intro: string
  /** Shown under the title so a reader knows how current the text is. */
  updated: string
  sections: LegalSection[]
  /** Set on policy documents; company pages (about/contact/support/faq) leave it off. */
  disclaimer?: string
}
