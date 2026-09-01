import type { AttributionRung, LeadAttribution } from './types'

/**
 * LEAD-SOURCE-ATTRIBUTION-001 — the chain behind one lead, and what it cannot say.
 *
 * The client's question is «which ad produced this person». The honest answer often has a hole in
 * it, and the entire value of this component is that it distinguishes the KINDS of hole rather than
 * printing one dash for all of them:
 *
 *   - a rung the platform never returns is a stated limit, in the platform's own terms;
 *   - a rung the platform DOES return and this lead lacks is our defect, and is marked as one;
 *   - a lead no platform is claiming is a different thing again, and says so once at the top rather
 *     than four times down the list.
 *
 * A reader who cannot tell those apart eventually concludes the product is broken — or, worse, that
 * it is fine, while a sync has been dropping ad ids for a month.
 */
const RUNG_LABEL: Record<AttributionRung['rung'], { ar: string; en: string }> = {
  creative: { ar: 'المحتوى', en: 'Content' },
  ad: { ar: 'الإعلان', en: 'Ad' },
  adset: { ar: 'المجموعة الإعلانية', en: 'Ad set' },
  campaign: { ar: 'الحملة', en: 'Campaign' },
}

const WEB_LABEL: Record<string, { ar: string; en: string }> = {
  landing_page: { ar: 'صفحة الوصول', en: 'Landing page' },
  utm_source: { ar: 'utm_source', en: 'utm_source' },
  utm_medium: { ar: 'utm_medium', en: 'utm_medium' },
  utm_campaign: { ar: 'utm_campaign', en: 'utm_campaign' },
  utm_content: { ar: 'utm_content', en: 'utm_content' },
  utm_term: { ar: 'utm_term', en: 'utm_term' },
  click_id: { ar: 'معرّف النقرة', en: 'Click id' },
}

export function LeadAttributionTrail({
  attribution,
  locale,
}: {
  attribution: LeadAttribution
  locale: string
}) {
  const ar = locale === 'ar'
  const web = Object.entries(attribution.web)

  return (
    <div className="flex flex-col gap-4" data-testid="lead-attribution">
      <div className="flex flex-wrap items-baseline gap-2 text-sm">
        <span className="text-text-secondary">{ar ? 'وصل عبر' : 'Arrived via'}</span>
        <span className="font-medium" data-testid="lead-attribution-route">
          {ar ? attribution.route_label : attribution.route_label_en}
        </span>
        {attribution.platform.label != null && (
          <>
            <span className="text-text-muted">·</span>
            <span data-testid="lead-attribution-platform">
              {ar ? attribution.platform.label : (attribution.platform.label_en ?? attribution.platform.label)}
            </span>
          </>
        )}
        {/*
         * A platform string we have never modelled is named and flagged. Vouching for it silently
         * would mean the absent rungs below carry no explanation and no defect mark either — the
         * exact ambiguity this component exists to remove.
         */}
        {attribution.platform.state === 'unrecognised' && (
          <span className="text-warning text-xs" data-testid="lead-attribution-unrecognised">
            {ar ? 'منصة غير معروفة لهذا المنتج' : 'Platform not modelled here'}
          </span>
        )}
      </div>

      <ol className="border-border flex flex-col gap-0 rounded-lg border">
        {attribution.rungs.map((rung) => (
          <li
            key={rung.rung}
            className="border-border flex flex-col gap-1 border-b p-3 last:border-b-0"
            data-testid={`lead-rung-${rung.rung}`}
            data-state={rung.state}
          >
            <span className="text-text-secondary text-xs">
              {ar ? RUNG_LABEL[rung.rung].ar : RUNG_LABEL[rung.rung].en}
            </span>
            {rung.state === 'named' ? (
              <>
                <span className="font-medium">{rung.name ?? rung.id}</span>
                {rung.name != null && rung.id != null && (
                  <span className="text-text-muted tnum text-xs" dir="ltr">
                    {rung.id}
                  </span>
                )}
              </>
            ) : rung.state === 'not_offered' ? (
              <span className="text-text-secondary text-sm">
                {(ar ? rung.reason : (rung.reason_en ?? rung.reason)) ??
                  (ar ? 'لا تُرجع هذه المنصة هذا المستوى.' : 'This platform does not return this level.')}
              </span>
            ) : rung.state === 'missing' ? (
              /*
               * Marked, not dashed. The platform sends this and the lead has not got it, which is a
               * gap on OUR side — the one state on this screen somebody should act on.
               */
              <span className="text-warning text-sm" data-testid={`lead-rung-gap-${rung.rung}`}>
                {ar
                  ? 'ترسل هذه المنصة هذا المستوى، ولم يصل مع هذا العميل المحتمل.'
                  : 'This platform does send this level, and it did not arrive with this lead.'}
              </span>
            ) : (
              <span className="text-text-muted text-sm">
                {ar ? 'لا توجد منصة إعلانية وراء هذا العميل المحتمل.' : 'No ad platform is behind this lead.'}
              </span>
            )}
          </li>
        ))}
      </ol>

      {web.length > 0 && (
        <div className="flex flex-col gap-1" data-testid="lead-attribution-web">
          <span className="text-text-secondary text-xs">
            {ar ? 'ما حمله الرابط' : 'What the link carried'}
          </span>
          <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            {web.map(([key, value]) => (
              <div key={key} className="contents">
                <dt className="text-text-secondary">
                  {ar ? (WEB_LABEL[key]?.ar ?? key) : (WEB_LABEL[key]?.en ?? key)}
                </dt>
                <dd className="min-w-0 truncate" dir="ltr">
                  {value}
                </dd>
              </div>
            ))}
          </dl>
        </div>
      )}
    </div>
  )
}
