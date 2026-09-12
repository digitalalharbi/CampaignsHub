import { formatMix } from './formatMix'
import { creatives as countedCreatives } from '@/lib/counted'
import { Num } from '@/components/ui/Num'
import type { FormatRow } from './api'
import type { Locale } from '@/stores/ui'

/**
 * CONTENT-SUMMARY-COMPACT-001 — a compact, content-specific head, not a dashboard duplicate.
 *
 * ## What was there
 *
 * Thirteen full-size KPI cards, of which nine read «لا توجد بيانات» or «لم ترسله المنصة» on the
 * owner's own account. That is a wall of empty boxes above the thing the page is for, and it was my
 * own doing: CONTENT-KPI-TOTALS-001 correctly found that the library had no headline figures at all,
 * and answered it by naming every metric the catalogue holds. Truthful and unreadable.
 *
 * ## What a content reader is actually deciding
 *
 * «Did this content earn its money, and what KIND of content is earning it.» That is four figures
 * and one comparison, and the comparison is the part a dashboard cannot give them: the split of
 * spend across image, video, carousel — the only content question whose answer transfers to the
 * next brief.
 *
 * The other nine metrics are not deleted; they live on the creative's own page and in the popup,
 * where a reader has asked about one thing rather than scanned a shelf.
 *
 * ## Absence is still absence
 *
 * A figure the platform never sent reads «—», never 0. The strip is compact, not optimistic: the
 * rule that empty cards were honouring is kept, and only their size is gone.
 */
export function ContentSummary({
  figures,
  formats,
  creativesRead,
  currency,
  locale,
}: {
  /** The four headline figures, already formatted by the page that owns the money contract. */
  figures: { key: string; label: string; value: string }[]
  formats: FormatRow[] | undefined
  creativesRead: number | null
  currency: string | null
  locale: Locale
}) {
  const ar = locale === 'ar'
  const mix = formatMix(formats)

  /*
   * Nothing to summarise is a SENTENCE, never an empty shell.
   *
   * `EmptyHeadlineState` caught this the moment the strip was replaced: with no totals the figure
   * list is empty, and an empty `<dl>` under a heading is exactly the outcome the card's own
   * branch exists to prevent — a grid that looks like it failed to load rather than like a scope
   * nobody reported on. The grid below says which filter is empty; this says only that it is.
   */
  if (figures.length === 0 && mix.shares.length === 0) {
    return null
  }

  return (
    <section data-testid="content-summary" className="rounded-2xl border border-border bg-surface p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-bold text-text-primary">{ar ? 'أداء المحتوى' : 'Content performance'}</h2>
        {creativesRead !== null && creativesRead > 0 && (
          <span data-testid="content-summary-count" className="text-[11px] text-text-secondary">
            {countedCreatives(creativesRead, locale)}
          </span>
        )}
      </div>

      {/*
        Four figures, compact. `text-start` with the value directly under its label — KPI-ALIGNMENT-002,
        and the reason these are a row of small blocks rather than cards is that a reader scans them
        and then goes to the grid.
      */}
      {figures.length > 0 && (
      <dl data-testid="content-summary-figures" className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {figures.map((f) => (
          <div key={f.key} data-testid={`content-summary-${f.key}`} className="text-start">
            <dt className="text-[11px] font-semibold text-text-muted">{f.label}</dt>
            <dd className="tnum mt-0.5 text-lg font-extrabold leading-tight text-text-primary">
              <Num>{f.value}</Num>
            </dd>
          </div>
        ))}
      </dl>
      )}

      {/*
        The split of spend by format — the one comparison this page owes that Analytics does not.
        
        Drawn only where it says something: one format is not a mix, and a bar with a single full-width
        slice is a decoration that looks like a finding.
      */}
      {mix.shares.length > 1 && (
        <div data-testid="content-summary-mix" className="mt-4">
          <div className="mb-1.5 flex items-baseline justify-between gap-2">
            <span className="text-[11px] font-semibold text-text-muted">
              {ar ? 'توزيع الإنفاق حسب نوع المحتوى' : 'Spend by content type'}
            </span>
            {/*
              A share over an incomplete total overstates itself — the rule the server already applies.
              A format whose money could not be added is NAMED beside the bar rather than folded into
              it, so a reader can see the bar does not account for everything.
            */}
            {mix.withheld.length > 0 && (
              <span data-testid="content-summary-mix-withheld" className="text-[10px] text-warning">
                {ar
                  ? `لا يشمل ${mix.withheld.join('، ')} — تعذّر جمع إنفاقها`
                  : `Excludes ${mix.withheld.join(', ')} — their spend could not be added`}
              </span>
            )}
          </div>

          <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-surface-secondary">
            {mix.shares.map((s, i) => (
              <span
                key={s.format}
                data-testid={`content-summary-slice-${s.format}`}
                style={{ width: `${(s.share * 100).toFixed(2)}%`, background: SLICE[i % SLICE.length] }}
                title={`${s.format} · ${Math.round(s.share * 100)}%`}
              />
            ))}
          </div>

          <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1">
            {mix.shares.map((s, i) => (
              <li key={s.format} className="flex items-center gap-1.5 text-[11px] text-text-secondary">
                <span className="h-2 w-2 rounded-full" style={{ background: SLICE[i % SLICE.length] }} aria-hidden />
                <span className="font-semibold text-text-primary">{formatLabel(s.format, ar)}</span>
                <span className="tnum" dir="ltr">{Math.round(s.share * 100)}%</span>
                <span className="text-text-muted">
                  · {currency ? `${Math.round(s.spend).toLocaleString('en-US')} ${currency}` : Math.round(s.spend).toLocaleString('en-US')}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}

/** Enough hues for the five canonical shapes, from the product's own brand ramp. */
const SLICE = ['var(--brand-600)', 'var(--brand-400)', 'var(--success)', 'var(--warning)', 'var(--text-muted)']

/** The shapes, in the reader's language — the same five the filters name. */
function formatLabel(format: string, ar: boolean): string {
  const labels: Record<string, [string, string]> = {
    image: ['صورة', 'Image'],
    video: ['فيديو', 'Video'],
    carousel: ['دوّار', 'Carousel'],
    collection: ['تشكيلة', 'Collection'],
    catalog: ['كتالوج', 'Catalog'],
  }

  const pair = labels[format]

  return pair === undefined ? format : (ar ? pair[0] : pair[1])
}
