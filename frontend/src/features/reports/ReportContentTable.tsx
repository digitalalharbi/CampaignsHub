import { Num } from '@/components/ui/Num'
import { AdPoster } from '@/features/content/AdPoster'
import { DeliveryBadge } from '@/features/content/DeliveryBadge'
import { relevanceOf } from '@/features/campaigns/campaignRelevance'
import { providerLabel } from '@/features/campaigns/labels'
import type { Locale } from '@/stores/ui'
import { figuresFor, type ReportAd } from './ReportAdsSection'

/**
 * REPORT-CONTENT-BROWSER-001 — the same creatives, as a table.
 *
 * The owner: «يجب إضافة ميزة العرض كجدول أو بطاقات كذلك في التقارير … توازي عرض المنصات الإعلانية
 * لكن الفرق ميزة المعاينة وصورة الغلاف والاحترافية بالتقييم والتقارير لها في مكان واحد».
 *
 * ## One dataset, one arithmetic
 *
 * It renders the rows the cards render, through `figuresFor` — the same function the live tile, the
 * snapshot card and the dialog already share. A table that computed its own figures would be a
 * second answer to «what did this creative do», and the first time the two disagreed nobody could
 * say which to believe. Nothing is re-sorted here either: the order arrives decided.
 *
 * ## No campaign column, and that is deliberate
 *
 * CLIENT-REPORT-ENTITY-BOUNDARY-001 — a client-facing report never carries campaign identity. The
 * client's question is «what happened, where, and which content worked»; the campaign's name,
 * configuration and management are the operator's product. A table is exactly where a campaign
 * column would feel natural to add, which is why the reason is written here rather than assumed.
 *
 * ## What it does carry
 *
 * The cover, because recognising the ad is the whole point of a content section; the platform; the
 * delivery state, so «why is this one first» is answerable; and the objective's own figures as
 * `figuresFor` chose them — never a fixed grid of columns with «—» where an objective does not
 * measure something.
 */
export function ReportContentTable({
  ads,
  locale,
  currency,
  windowEnd,
  onOpen,
}: {
  ads: ReportAd[]
  locale: Locale
  currency: string | null
  /** What «still running» is measured against — the report's period end, never today. */
  windowEnd: string | null
  onOpen?: (ad: ReportAd) => void
}) {
  const ar = locale === 'ar'

  /*
   * The figure COLUMNS are the union of what the rows actually report, in first-seen order.
   *
   * Not a fixed set: an awareness ad and a sales ad in one table do not answer the same questions,
   * and reserving a «ROAS» column for a brand film prints a dash that reads as a missing number
   * rather than an inapplicable one. Not per-row either — a table whose columns moved per row would
   * not be a table.
   */
  const columns: string[] = []

  for (const ad of ads) {
    for (const figure of figuresFor(ad, ar, currency)) {
      if (!columns.includes(figure.label)) columns.push(figure.label)
    }
  }

  return (
    <div className="overflow-x-auto rounded-2xl border border-border" data-testid="report-content-table">
      <table className="w-full min-w-[48rem] text-sm">
        <thead className="bg-surface-hover text-xs text-text-secondary">
          <tr>
            <th className="p-2 text-start">{ar ? 'المحتوى' : 'Content'}</th>
            <th className="p-2 text-start">{ar ? 'المنصة' : 'Platform'}</th>
            <th className="p-2 text-start">{ar ? 'حالة العرض' : 'Delivery'}</th>
            {columns.map((label) => (
              /*
               * TABLE-NUMERIC-ALIGNMENT-001 — a figure column is centred, and `dir` rides on the
               * numeral rather than on the cell. On the cell it re-bases the column's own start
               * edge, which is how a header and its figures end up at opposite edges under RTL.
               */
              <th key={label} className="p-2 text-center">{label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ads.map((ad, index) => {
            const figures = figuresFor(ad, ar, currency)
            const byLabel = new Map(figures.map((f) => [f.label, f.value]))

            return (
              <tr
                key={ad.id ?? `${ad.name}-${index}`}
                data-testid={`report-content-row-${index}`}
                onClick={onOpen === undefined ? undefined : () => onOpen(ad)}
                className={`border-t border-border ${onOpen === undefined ? '' : 'cursor-pointer hover:bg-surface-hover'}`}
              >
                <td className="p-2">
                  <div className="flex min-w-0 items-center gap-2">
                    {/*
                      CONTENT-THUMB-FILL-001 — a 64×40 thumbnail is a cover and fills its frame.
                      The whole ad is one click away in the viewer, which contains.
                    */}
                    <AdPoster
                      preview={ad.preview ?? null}
                      name={ad.name ?? ''}
                      className="h-10 w-16 shrink-0 rounded"
                      testid={`report-content-poster-${index}`}
                      fit="cover"
                      forClient
                    />
                    <span className="truncate font-medium text-text-primary" title={ad.name ?? undefined}>
                      {ad.name ?? '—'}
                    </span>
                  </div>
                </td>
                <td className="p-2 text-text-secondary">
                  {ad.provider ? providerLabel(ad.provider, locale) : '—'}
                </td>
                <td className="p-2">
                  {/*
                    The same rule the library and its card read. A report that called a creative
                    running while the library called it stopped would be two answers about one row.
                    Null facts read as «idle» rather than «stopped»: the platform not saying is not
                    the platform saying it ended.
                  */}
                  <DeliveryBadge
                    state={relevanceOf(
                      { status: ad.status ?? null, last_active_on: ad.last_active_at ?? null },
                      windowEnd ?? new Date().toISOString().slice(0, 10),
                    )}
                    ar={ar}
                  />
                </td>
                {columns.map((label) => (
                  <td key={label} className="p-2 text-center">
                    {/*
                      A figure this row does not report is a dash, and it is NOT a zero — the row
                      simply has no such column. `figuresFor` already withheld it.
                    */}
                    {byLabel.has(label)
                      ? <Num className="tnum font-semibold text-text-primary">{byLabel.get(label)}</Num>
                      : <span className="text-text-muted">—</span>}
                  </td>
                ))}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
