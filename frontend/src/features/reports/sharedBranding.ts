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
import { productName } from '@/lib/brand'
export interface SharedBranding {
  name: string
  logo_url: string | null
  /** Which layer the logo came from — `none` when nothing resolved. Never where the NAME came from. */
  logo_source: string
  /** The agency, shown secondarily as «بواسطة». Null when there is none to show. */
  by: string | null
  /**
   * REPORT BRANDING — the two marks, each its OWN layer's logo or null: the agency that issued the
   * report and the client it is about. Absent on a payload from before they existed.
   */
  agency?: { name: string; logo_url: string | null }
  client?: { name: string; logo_url: string | null } | null
}

export interface HeaderIdentity {
  name: string
  logoUrl: string | null
  by: string | null
  /** The agency's own mark beside «بواسطة» — null when it has none, or when there is no client. */
  byLogoUrl: string | null
}

/**
 * REPORT BRANDING (Owner) — a report's page title: its name ending with the product's, in the
 * REPORT's language. «<اسم التقرير> — كامبينز هب» / «<Report name> — CampaignsHub». The backend's
 * `ReportIdentity::pageTitle()` builds the same string for the pasted-link card and the file.
 */
export function reportPageTitle(name: string | null | undefined, reportLocale: string | null | undefined): string {
  const locale = reportLocale === 'en' ? 'en' : 'ar'
  const title = name?.trim() ? name.trim() : (locale === 'en' ? 'Performance report' : 'تقرير الأداء')

  return `${title} — ${productName(locale)}`
}

/**
 * A logo that WAS a valid URL and then failed to load.
 *
 * `headerIdentity` can only refuse an empty or missing url — the backend resolved a real one. It
 * cannot know that the asset was since deleted, or that the storage layer will refuse it. When that
 * happens the browser paints its broken-image icon on a client's report, which is precisely the
 * «never a broken image or blank header» this requirement forbids, and it looks like the REPORT
 * failed rather than the logo.
 *
 * So the consumer hides the image on error and lets the name stand alone. The name is already the
 * last link of the client → agency → CampaignsHub chain, so there is always something to fall back
 * to and never an empty header.
 */
export function hideBrokenLogo(event: { currentTarget: { style: { display: string } } }): void {
  event.currentTarget.style.display = 'none'
}

export function headerIdentity(branding: SharedBranding | undefined, locale?: string): HeaderIdentity {
  /*
   * An empty name is not a name. A header with no text is indistinguishable from a page that failed
   * to load, so the product's own name stands in — this is the last link of the same
   * client → agency → CampaignsHub chain, not a separate default.
   */
  const name = branding?.name?.trim() ? branding.name : productName(locale)

  /*
   * An empty string is not a URL. `<img src="">` re-requests the page itself in some browsers and
   * renders a broken icon in others — on a client's report that looks like the report failed, which
   * is worse than showing no mark at all.
   */
  const clean = (url: string | null | undefined) => (url?.trim() ? url : null)
  /*
   * With the two marks resolved, each goes in its own place: the client's leads, the agency's sits
   * beside «بواسطة». The nearest-logo field would put the agency's mark in the client's place when
   * the client has none. A frozen identity (`report`) keeps its one mark, as it was shared.
   */
  const named = branding?.agency !== undefined && branding.logo_source !== 'report'
  const logoUrl = named
    ? clean(branding.client ? branding.client.logo_url : branding.agency?.logo_url)
    : clean(branding?.logo_url)

  /*
   * The agency is named secondarily or not at all — never in place of the client, and never when it
   * IS the client, because «Nakheel, by Nakheel» reads as a bug rather than as provenance.
   */
  const by = branding?.by?.trim() && branding.by !== name ? branding.by : null

  const byLogoUrl = named && by !== null && branding.client ? clean(branding.agency?.logo_url) : null

  return { name, logoUrl, by, byLogoUrl }
}
