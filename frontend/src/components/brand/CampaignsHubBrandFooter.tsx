import { brand } from '@/lib/brand'
import { CampaignsHubMark } from './CampaignsHubMark'

/**
 * BRAND-ATTRIBUTION-001 — CampaignsHub's credit on work a client actually receives.
 *
 * Deliberately SECONDARY. `BRANDING-HIERARCHY-001` resolves Platform → Agency → Client, and on a
 * client-facing report the agency's or the client's own brand leads; putting an oversized
 * CampaignsHub logo over it would take a deliverable somebody sells and turn it into an
 * advertisement for the tool that made it. So this is a footer row: small mark, copyright, domain.
 *
 * `cta` is offered only where a report is a live digital surface somebody can act from — a shared
 * link, not a printed page — and stays a quiet line rather than a banner. The owner's rule is the
 * measure: «لا تحوّل تقارير العميل إلى إعلانات».
 */
export function CampaignsHubBrandFooter({
  locale = 'en',
  cta = false,
  year,
  className = '',
}: {
  locale?: 'ar' | 'en'
  /** Show the restrained «create reports with CampaignsHub» line. Shared/live surfaces only. */
  cta?: boolean
  /**
   * The year to print. Passed in rather than read from the clock here, because a SNAPSHOT report is
   * a record of a period and re-rendering it years later must not silently restamp it as current.
   */
  year: number
  className?: string
}) {
  const ar = locale === 'ar'
  const site = `https://${brand.domain}`

  return (
    <footer
      data-testid="campaignshub-brand-footer"
      className={`mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4 text-xs text-text-muted ${className}`}
    >
      <span>
        {ar
          ? `© ${year} ${brand.lockup.nameAr} — جميع الحقوق محفوظة`
          : `© ${year} ${brand.lockup.nameEn}. All rights reserved.`}
      </span>

      <span className="flex items-center gap-3">
        <a
          href={site}
          target="_blank"
          rel="noopener noreferrer"
          data-testid="powered-by-campaignshub"
          className="inline-flex items-center gap-2 hover:text-text-primary"
        >
          <CampaignsHubMark size={16} className="text-brand-mark" />
          <span>{ar ? `مدعوم بواسطة ${brand.lockup.nameAr}` : `Powered by ${brand.lockup.nameEn}`}</span>
        </a>
        <span className="hidden sm:inline">{brand.domain}</span>
      </span>

      {cta && (
        <a
          href={site}
          target="_blank"
          rel="noopener noreferrer"
          data-testid="campaignshub-cta"
          className="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm font-medium transition hover:bg-surface-hover hover:text-text-primary"
        >
          <CampaignsHubMark size={18} className="text-brand-mark" />
          <span>{ar ? `أنشئ تقاريرك عبر ${brand.lockup.nameAr}` : `Create reports with ${brand.lockup.nameEn}`}</span>
        </a>
      )}
    </footer>
  )
}
