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
  loading = false,
  formatsPending = false,
}: {
  /** The four headline figures, already formatted by the page that owns the money contract. */
  figures: { key: string; label: string; value: string }[]
  formats: FormatRow[] | undefined
  creativesRead: number | null
  currency: string | null
  locale: Locale
  /**
   * Whether the FORMAT query — a different request from the figures' — is still in flight.
   *
   * This is the whole of CONTENT-SUMMARY-RESERVE-002. `loading` below is `libraryQuery.isPending`
   * and the mix is drawn from `intelligence`, so on a browser where the second response lands after
   * the first (webkit, consistently; chromium, not) the block painted its resolved layout with
   * `formats === undefined`, drew no mix, and then GREW by the mix's own height when the formats
   * arrived. The webkit gate measured the toolbar under it moving 77px.
   *
   * Reserving in the skeleton was never enough, because the skeleton had already gone. `undefined`
   * alone cannot carry this: it is also what an error leaves behind, and a block that pulses for
   * ever is worse than one that says it could not load.
   */
  formatsPending?: boolean
  /**
   * KPI-STRIP-RESERVE-001, again — a block that appears after the page has painted moves everything
   * under it.
   *
   * The thirteen-card strip this replaced had exactly this bug and exactly this fix, and dropping
   * the prop while shrinking the block reintroduced it: `creative-analysis` measured the library's
   * view toggle jumping 53px once the figures landed, which a person meets as reaching for «list»
   * and hitting the search box.
   */
  loading?: boolean
}) {
  const ar = locale === 'ar'
  const mix = formatMix(formats)

  /*
   * While the request is in flight the block holds its own shape and says nothing about the figures.
   *
   * Four blocks because the key list is a constant — the page knows how many it will draw before it
   * has a single number.
   */
  if (loading) {
    return (
      <section data-testid="content-summary" data-state="loading" className="rounded-2xl border border-border bg-surface p-4">
        <div className="h-5 w-40 animate-pulse rounded bg-surface-secondary" />
        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[0, 1, 2, 3].map((i) => (
            <div key={i}>
              {/*
                The SAME two heights the resolved figure declares — `FIGURE_LABEL` and `FIGURE_VALUE`
                below, not a second description of them. Writing the skeleton's rows by eye is how
                this block came to be 50px shorter than what replaced it the first time.
              */}
              <div className={`${FIGURE_LABEL} w-16 animate-pulse rounded bg-surface-secondary`} />
              <div className={`${FIGURE_VALUE} mt-0.5 w-24 animate-pulse rounded bg-surface-secondary`} />
            </div>
          ))}
        </div>
        <MixPlaceholder />
      </section>
    )
  }

  /*
   * Nothing to summarise is a SENTENCE, never an empty shell.
   *
   * `EmptyHeadlineState` caught this the moment the strip was replaced: with no totals the figure
   * list is empty, and an empty `<dl>` under a heading is exactly the outcome the card's own
   * branch exists to prevent — a grid that looks like it failed to load rather than like a scope
   * nobody reported on. The grid below says which filter is empty; this says only that it is.
   */
  if (figures.length === 0 && mix.shares.length === 0 && ! formatsPending) {
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
            <dt className={`${FIGURE_LABEL} text-[11px] font-semibold leading-4 text-text-muted`}>{f.label}</dt>
            <dd className={`${FIGURE_VALUE} tnum mt-0.5 text-lg font-extrabold leading-6 text-text-primary`}>
              <Num>{f.value}</Num>
            </dd>
          </div>
        ))}
      </dl>
      )}

      {/*
        The split of spend by format — the one comparison this page owes that Analytics does not.

        Drawn whenever anything can be priced, INCLUDING a single format. A first version drew it
        only for two or more, on the reasoning that one format is not a mix — true, and it made the
        block's height depend on the data, which moved the toolbar underneath it by 73px once the
        figures landed. `creative-analysis` measures exactly that, and a reader meets it as reaching
        for «list» and hitting the search box.
        
        A single slice with «video · 100% · 12,400 SAR» beside it is not the decoration that worry
        was about: it states which shape the whole budget went to, which is a fact about the account
        and the same fact the bar states when there are four.
      */}
      {mix.shares.length === 0 ? (
        /*
         * The region keeps its height in every state, because the height is what moves the toolbar.
         *
         * Three states and three different sentences: still asking, asked and nothing could be
         * priced, and the bar itself. The middle one is not filler holding space — «no spend could
         * be attributed to a content type» is the answer to the question the bar exists to ask, and
         * a reader who sees the heading with nothing under it concludes the chart failed.
         */
        formatsPending ? <MixPlaceholder /> : (
          <div data-testid="content-summary-mix" data-state="none" className="mt-4">
            <div className={`${MIX_LABEL_ROW} mb-1.5 flex items-baseline`}>
              <span className="text-[11px] font-semibold text-text-muted">
                {ar ? 'توزيع الإنفاق حسب نوع المحتوى' : 'Spend by content type'}
              </span>
            </div>
            <div className={MIX_BAR} />
            <p className={`${MIX_LEGEND_ROW} mt-2 flex items-center text-[11px] text-text-secondary`}>
              {ar
                ? 'لا يمكن نسب أي إنفاق إلى نوع محتوى في هذه الفترة.'
                : 'No spend could be attributed to a content type in this period.'}
            </p>
          </div>
        )
      ) : (
        <div data-testid="content-summary-mix" data-state="ready" className="mt-4">
          <div className={`${MIX_LABEL_ROW} mb-1.5 flex items-baseline justify-between gap-2`}>
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

          <div className={`${MIX_BAR} flex overflow-hidden`}>
            {mix.shares.map((s, i) => (
              <span
                key={s.format}
                data-testid={`content-summary-slice-${s.format}`}
                style={{ width: `${(s.share * 100).toFixed(2)}%`, background: SLICE[i % SLICE.length] }}
                title={`${s.format} · ${Math.round(s.share * 100)}%`}
              />
            ))}
          </div>

          <ul className={`${MIX_LEGEND_ROW} mt-2 flex flex-wrap items-center gap-x-4 gap-y-1`}>
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

/*
 * CONTENT-SUMMARY-RESERVE-002 — every reserved height declared ONCE.
 *
 * This block has now moved the toolbar under it three times: 53px when it had no loading state at
 * all, 73px when the mix drew only for more than one format, and 77px when the mix waited on a
 * second request that lands later on webkit than on chromium. Each fix was correct and the next
 * break was the same shape, because the skeleton and the real thing were two descriptions of one
 * layout and nothing held them together.
 *
 * They are one description now. A row whose height is a constant cannot disagree with itself, and
 * `min-h` rather than `h` on the legend lets it wrap on a narrow screen without collapsing on a
 * wide one.
 */
const FIGURE_LABEL = 'h-4'

const FIGURE_VALUE = 'h-6'

const MIX_LABEL_ROW = 'h-4'

const MIX_BAR = 'h-2.5 w-full rounded-full bg-surface-secondary'

const MIX_LEGEND_ROW = 'min-h-[1.125rem]'

/** The mix region before it can say anything — the same rows it will occupy once it can. */
function MixPlaceholder() {
  return (
    <div data-testid="content-summary-mix" data-state="loading" className="mt-4">
      <div className={`${MIX_LABEL_ROW} mb-1.5 w-44 animate-pulse rounded bg-surface-secondary`} />
      <div className={`${MIX_BAR} animate-pulse`} />
      <div className={`${MIX_LEGEND_ROW} mt-2 w-64 animate-pulse rounded bg-surface-secondary`} />
    </div>
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
