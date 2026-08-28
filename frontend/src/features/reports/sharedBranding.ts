/**
 * BRANDING-HIERARCHY-001 — what a shared report's header does with the identity the backend resolved.
 *
 * The resolution itself is the backend's: it alone knows the tenant, and it walks
 * client → agency → platform through the Branding Center the operator actually configures. Deciding
 * any of that here would be a second branding engine, and the two would drift.
 *
 * These are only the rules the header must not get wrong, and each of them is one a reader would
 * notice immediately.
 */
export interface SharedBranding {
  name: string
  logo_url: string | null
  /** Which layer the logo came from — `none` when nothing resolved. Never where the NAME came from. */
  logo_source: string
  /** The agency, shown secondarily as «بواسطة». Null when there is none to show. */
  by: string | null
}

export interface HeaderIdentity {
  name: string
  logoUrl: string | null
  by: string | null
}

export function headerIdentity(branding: SharedBranding | undefined): HeaderIdentity {
  /*
   * An empty name is not a name. A header with no text is indistinguishable from a page that failed
   * to load, so the product's own name stands in — this is the last link of the same
   * client → agency → CampaignsHub chain, not a separate default.
   */
  const name = branding?.name?.trim() ? branding.name : 'CampaignsHub'

  /*
   * An empty string is not a URL. `<img src="">` re-requests the page itself in some browsers and
   * renders a broken icon in others — on a client's report that looks like the report failed, which
   * is worse than showing no mark at all.
   */
  const logoUrl = branding?.logo_url?.trim() ? branding.logo_url : null

  /*
   * The agency is named secondarily or not at all — never in place of the client, and never when it
   * IS the client, because «Nakheel, by Nakheel» reads as a bug rather than as provenance.
   */
  const by = branding?.by?.trim() && branding.by !== name ? branding.by : null

  return { name, logoUrl, by }
}
