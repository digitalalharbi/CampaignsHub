import { MetricTable } from '@/components/ui/MetricTable'
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
 * say which to believe. Nothing is re-sorted here either: the order arrives decided, which is why
 * the primitive is given no sort values and draws no sort controls.
 *
 * ## It is `MetricTable`, not a table of its own
 *
 * TABLE-PRESENTATION-CONTRACT-001 holds one analytical table for the product and a list of the
 * surfaces still outside it that is only allowed to shrink. The first version of this file was a
 * hand-rolled `<table>`, and the contract's guard refused it on the lane — correctly: a client-facing
 * report table with its own answer to alignment, row headers and the sideways scroll is exactly the
 * sixth copy the contract exists to prevent. So the cells are composed here and the table is the
 * primitive's: the first cell is the row's header for a screen reader, every figure column is
 * centred under `dir="rtl"` for the recorded reason, and the wrapper contains instead of the page
 * scrolling sideways on a phone.
 *
 * ## The press lives on the name, not on the row
 *
 * A pressable `<tr>` is reachable by a mouse and by nothing else. The poster and the name are one
 * button, so the viewer is a Tab and an Enter away as well as a click — and on the printed page,
 * where there is nothing to open, it is plain text rather than a control that cannot be pressed.
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

  const head = [ar ? 'المحتوى' : 'Content', ar ? 'المنصة' : 'Platform', ar ? 'حالة العرض' : 'Delivery', ...columns]

  const rows = ads.map((ad, index) => {
    const figures = figuresFor(ad, ar, currency)
    const byLabel = new Map(figures.map((f) => [f.label, f.value]))

    const identity = (
      <>
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
      </>
    )

    return [
      onOpen === undefined ? (
        <span key="open" className="flex min-w-0 items-center gap-2" data-testid={`report-content-open-${index}`}>{identity}</span>
      ) : (
        <button
          key="open"
          type="button"
          onClick={() => onOpen(ad)}
          data-testid={`report-content-open-${index}`}
          className="flex min-w-0 items-center gap-2 text-start hover:underline"
        >
          {identity}
        </button>
      ),
      <span key="platform" className="text-text-secondary">{ad.provider ? providerLabel(ad.provider, locale) : '—'}</span>,
      /*
       * The same rule the library and its card read. A report that called a creative running while
       * the library called it stopped would be two answers about one row. Null facts read as «idle»
       * rather than «stopped»: the platform not saying is not the platform saying it ended.
       */
      <DeliveryBadge
        key="delivery"
        state={relevanceOf(
          { status: ad.status ?? null, last_active_on: ad.last_active_at ?? null },
          windowEnd ?? new Date().toISOString().slice(0, 10),
        )}
        ar={ar}
      />,
      ...columns.map((label) =>
        /*
         * A figure this row does not report is a dash, and it is NOT a zero — the row simply has
         * no such column. `figuresFor` already withheld it.
         */
        byLabel.has(label)
          ? <Num key={label} className="tnum font-semibold text-text-primary">{byLabel.get(label)}</Num>
          : <span key={label} className="text-text-muted">—</span>,
      ),
    ]
  })

  return (
    <div className="rounded-2xl border border-border px-3" data-testid="report-content-table">
      <MetricTable head={head} rows={rows} />
    </div>
  )
}
