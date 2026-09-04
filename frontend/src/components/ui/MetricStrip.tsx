import { useId, useState } from 'react'
import { Area, AreaChart, ResponsiveContainer } from 'recharts'
import { ArrowDownRight, ArrowUpRight, ChevronDown, ChevronUp, Info, Minus } from 'lucide-react'
import { QueryFailure } from './QueryFailure'
import { TOUCH_CONTROL, TOUCH_TARGET } from './touch'
import { CARD_GAP, CARD_PAD_DENSE, METRIC_HINT, METRIC_LABEL, METRIC_VALUE, METRIC_VALUE_DENSE } from '@/styles/scale'

/**
 * The metrics that matter first, and the honest absence of the rest — UX-METRICS-001.
 *
 * ## Priority is the feature
 *
 * A row of fourteen equally-sized cards is not a summary; it is a table with bigger fonts, and it
 * forces every reader to do the ranking the product should have done for them. So a strip takes
 * `primary` and `secondary`, and the split is by the campaign's OBJECTIVE — an awareness campaign
 * leads with reach, impressions, frequency and CPM; a sales campaign leads with purchases, CPA,
 * revenue and ROAS. The rest are one visible click away, ON the page, never behind a dialog.
 *
 * ## Three absences, three different sentences
 *
 * `not_provided` — the platform does not report this metric at all. `no_data` — it reports it, but
 * nothing arrived for this window, or a ratio has no denominator. `stale` — the figure is real but
 * the last sync is old enough that the reader should know before they act on it. None of the three
 * is a zero, and the strip has no code path that turns them into one: `reading` is a tagged union
 * precisely so `value ?? 0` cannot be written by accident at a call site.
 *
 * ## Comparison belongs to the strip, not to the card
 *
 * «vs the previous 30 days» is said once above the row rather than fourteen times inside it. The
 * cards carry only the delta and its direction, which is the part that differs between them.
 */

export type MetricReading =
  /**
   * A real, measured figure — already formatted, including a real zero.
   *
   * `exact` is the same figure written out in full, present only when `text` abbreviated it —
   * NUMBER-PRESENTATION-001. A card has room for «4.85M SAR» and a reader comparing two of them
   * eventually needs the rest of the digits; the compact form is the display, never the record.
   */
  | { kind: 'value'; text: string; exact?: string }
  /** The platform does not report this metric. */
  | { kind: 'not_provided' }
  /** Reported, but nothing for this window — or a ratio whose denominator is missing. */
  | { kind: 'no_data' }
  /**
   * The platform DID report it, and we cannot convert it to the project's currency.
   *
   * FX-WITHHELD-UI-001. This is not «no data» and it is certainly not zero. FX-001 withholds a money
   * figure when no exchange rate exists for the day, because a converted number would be invented.
   * Without this variant the withheld null fell through to `no_data`, and a project whose platform
   * reported 3,465.33 USD read «0» under «لم ترسله المنصة» — a sentence the payload disproves.
   *
   * `original` is the platform's own figure, already formatted WITH its own currency, so the reader
   * sees the real amount and is told plainly why it is not in their currency.
   */
  | { kind: 'withheld'; original: string }

export type MetricItem = {
  key: string
  label: string
  reading: MetricReading
  /** Change against the comparison window, as a ratio (0.12 = +12%). `null` when incomparable. */
  delta?: number | null
  /** True where DOWN is the good direction — cost per result, CPC, CPA. */
  invertGood?: boolean
  /**
   * True where neither direction is good — frequency, impressions on a sales campaign.
   *
   * Without it every delta is coloured, and colour is a judgement: a rising frequency painted green
   * tells the reader their ads being shown more often to the same people is going well, which is
   * the opposite of what an operator would say about it.
   */
  neutral?: boolean
  /** What this metric means, in one sentence. Shown on hover and on focus. */
  hint?: string
  spark?: number[]
  /** Draws the eye to the metric the objective is actually judged on. */
  lead?: boolean
}

const T = {
  notProvided: { ar: 'لم ترسله المنصة', en: 'Not provided' },
  noData: { ar: 'لا توجد بيانات', en: 'No data' },
  loading: { ar: 'جارِ تحميل المؤشرات', en: 'Loading metrics' },
  loadFailed: { ar: 'تعذّر تحميل المؤشرات', en: 'These metrics could not be loaded' },
  withheldHint: {
    ar: 'المنصة أرسلت هذا الرقم، ولا يتوفر سعر صرف لتحويله إلى عملة المشروع.',
    en: 'The platform reported this figure; no exchange rate is available to convert it.',
  },
  withheldNote: {
    ar: 'التحويل إلى عملة المشروع غير متاح حاليًا',
    en: 'Conversion to the project currency is unavailable',
  },
  notProvidedHint: {
    ar: 'هذه المنصة لا ترسل هذا المؤشر — والقيمة ليست صفرًا.',
    en: 'This platform does not report this metric — the value is not zero.',
  },
  noDataHint: {
    ar: 'لا توجد بيانات لهذه الفترة، أو لا يمكن حساب النسبة.',
    en: 'Nothing arrived for this period, or the ratio cannot be computed.',
  },
  more: { ar: 'مؤشرات إضافية', en: 'More metrics' },
  less: { ar: 'إخفاء المؤشرات الإضافية', en: 'Hide extra metrics' },
  vs: { ar: 'مقارنة بـ', en: 'vs' },
  definition: { ar: 'تعريف المؤشر', en: 'What this means' },
}

const t = (key: keyof typeof T, ar: boolean) => (ar ? T[key].ar : T[key].en)

/**
 * A definition, on hover and on focus.
 *
 * Not the native `title` attribute: it never appears on a touch screen and never appears for a
 * keyboard user, so on a phone — which is where half of this product is read — a `title` is a
 * tooltip that does not exist.
 */
export function InfoHint({ text, label }: { text: string; label: string }) {
  const [open, setOpen] = useState(false)
  const id = useId()

  return (
    <span className="relative inline-flex">
      <button
        type="button"
        aria-label={label}
        aria-describedby={open ? id : undefined}
        aria-expanded={open}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onClick={() => setOpen((o) => !o)}
        className={`rounded-full p-0.5 text-text-muted hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 ${TOUCH_TARGET}`}
      >
        <Info size={13} aria-hidden />
      </button>
      {open && (
        <span
          role="tooltip"
          id={id}
          className="absolute top-full z-40 mt-1 w-56 rounded-xl border border-border-strong bg-surface p-2 text-xs font-normal leading-relaxed text-text-secondary shadow-[var(--shadow-medium)] start-0"
        >
          {text}
        </span>
      )}
    </span>
  )
}

function Delta({
  delta,
  invertGood,
  neutral,
  ar,
}: {
  delta: number
  invertGood?: boolean
  neutral?: boolean
  ar: boolean
}) {
  const flat = Math.abs(delta) < 0.0005
  const up = delta > 0
  const good = flat || neutral ? null : invertGood ? !up : up
  const Icon = flat ? Minus : up ? ArrowUpRight : ArrowDownRight
  const tone = good === null
    ? 'text-text-muted bg-surface-secondary'
    : good
      ? 'text-success bg-[var(--positive-background)]'
      : 'text-danger bg-[var(--negative-background)]'

  return (
    <span
      dir="ltr"
      className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-semibold ${tone}`}
      aria-label={`${ar ? 'التغير' : 'Change'} ${(delta * 100).toFixed(0)}%`}
    >
      <Icon size={13} aria-hidden />
      {`${Math.abs(delta * 100).toFixed(0)}%`}
    </span>
  )
}

export function MetricCard({ item, ar }: { item: MetricItem; ar: boolean }) {
  /*
   * A withheld figure is NOT missing. It has a real number to show, so it must not take the muted
   * «nothing here» treatment that `not_provided` and `no_data` share — that treatment is what made
   * 3,465.33 USD look like an absence.
   */
  const missing = item.reading.kind === 'not_provided' || item.reading.kind === 'no_data'
  const missingText =
    item.reading.kind === 'not_provided' ? t('notProvided', ar) : t('noData', ar)
  const missingHint =
    item.reading.kind === 'not_provided' ? t('notProvidedHint', ar) : t('noDataHint', ar)

  return (
    <div
      data-testid={`metric-${item.key}`}
      data-state={item.reading.kind}
      className={`flex flex-col gap-1.5 rounded-2xl border bg-surface ${CARD_PAD_DENSE} ${
        item.lead
          ? 'border-brand-500/50 ring-1 ring-brand-500/20 shadow-[var(--shadow-small)]'
          : 'border-border'
      }`}
    >
      <div className="flex items-start justify-between gap-1">
        <span className={`inline-flex items-center gap-1 text-text-secondary ${METRIC_LABEL}`}>
          {item.label}
          {item.hint && <InfoHint text={item.hint} label={`${t('definition', ar)}: ${item.label}`} />}
        </span>
        {/*
          A delta only where there is a figure to compare. «+12%» beside «Not provided» would be a
          comparison of two absences, printed as a change.
        */}
        {!missing && item.delta !== null && item.delta !== undefined && (
          <Delta delta={item.delta} invertGood={item.invertGood} neutral={item.neutral} ar={ar} />
        )}
      </div>

      {item.reading.kind === 'value' ? (
        <span
          dir="ltr"
          // `title` rather than a custom tooltip: it is the one hover that also works for a keyboard
          // user's screen reader and survives being inside a chart card, a table cell or a PDF print.
          title={item.reading.exact}
          /*
           * `dir="ltr"` and `text-start` are two different settings and the card needs both. The
           * first keeps «56.3K SAR» in digit order inside an Arabic page; without the second the
           * span inherits its own LTR alignment, so the figure drifts to the left edge while its
           * label stays at the right — the pair stops reading as one thing.
           */
          className={`block text-start text-text-primary ${item.lead ? METRIC_VALUE : METRIC_VALUE_DENSE}`}
        >
          {item.reading.text}
        </span>
      ) : item.reading.kind === 'withheld' ? (
        /*
          FX-WITHHELD-UI-001 — the real figure, at full weight, with the reason underneath.

          It reads at the same size as a converted number because it IS the number the platform
          reported; only the currency is not the reader's. `dir="ltr"` keeps «3,465.33 USD» in Latin
          order inside an RTL page, exactly as a converted figure is kept.
        */
        <span className="flex flex-col gap-0.5">
          <span dir="ltr" className={`block text-start text-text-primary ${item.lead ? METRIC_VALUE : METRIC_VALUE_DENSE}`}>
            {item.reading.original}
          </span>
          <span className={`inline-flex items-center gap-1 font-medium text-text-muted ${METRIC_HINT}`}>
            {t('withheldNote', ar)}
            <InfoHint text={t('withheldHint', ar)} label={t('withheldNote', ar)} />
          </span>
        </span>
      ) : (
        <span className="inline-flex items-center gap-1 py-1 text-sm font-semibold text-text-muted">
          {missingText}
          <InfoHint text={missingHint} label={missingText} />
        </span>
      )}

      {!missing && item.spark && item.spark.length > 1 && (
        <div className="h-8 w-full">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={item.spark.map((v, i) => ({ i, v }))} margin={{ top: 4, bottom: 0, left: 0, right: 0 }}>
              <defs>
                <linearGradient id={`spark-${item.key}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor="var(--brand-600)" stopOpacity={0.32} />
                  <stop offset="100%" stopColor="var(--brand-600)" stopOpacity={0} />
                </linearGradient>
              </defs>
              <Area
                type="monotone"
                dataKey="v"
                stroke="var(--brand-600)"
                strokeWidth={2}
                fill={`url(#spark-${item.key})`}
                isAnimationActive={false}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  )
}

export function MetricStrip({
  id,
  ar,
  primary,
  secondary = [],
  comparisonLabel,
  note,
  hasRows,
  loading = false,
  error,
  onRetry,
}: {
  id: string
  ar: boolean
  /** What this objective is judged on — four to six, never fourteen. */
  primary: MetricItem[]
  /** Everything else, one visible click away and still on the page. */
  secondary?: MetricItem[]
  /** «آخر 30 يومًا» — said once, above the row. */
  comparisonLabel?: string
  /** Freshness, a caveat, whatever the row needs said beside it rather than under each card. */
  note?: string
  /**
   * METRICS-EMPTY-SCOPE-001 — whether the current filters match any row at all.
   *
   * `false` replaces the whole row with one sentence about the FILTER. Left undefined by callers
   * that have no scope to speak of, which keeps every existing use unchanged.
   */
  hasRows?: boolean
  /**
   * METRICS-REQUEST-STATE-001 — the request has not answered yet.
   *
   * Without this the strip rendered anyway: with no totals to read, every card fell to `no_data` and
   * printed «لا توجد بيانات» — an absence of evidence rendered as evidence of absence.
   */
  loading?: boolean
  /** The failed request, passed straight to `QueryFailure` so a refusal reads as a refusal. */
  error?: unknown
  onRetry?: () => void
}) {
  const [expanded, setExpanded] = useState(false)

  /*
   * METRICS-REQUEST-STATE-001 — a failure outranks everything below it.
   *
   * Both a failure and an empty scope can be true in the props at once — the previous scope was
   * empty and the refetch then failed — and «your filter matched nothing» is a claim the failed
   * request gave no standing to make. `QueryFailure` owns the four arms (refusal, expired session,
   * missing record, dead server) so this surface cannot drift into a fifth.
   */
  /*
   * FILTER-LOCALE-EMPTY-STATE-OBS — which of the four arms ran, said in the DOM.
   *
   * This strip has failed intermittently in the gate for three runs: `metric-roas` is absent within
   * twenty seconds of choosing an objective, deep into a long run, never in isolation. Four arms can
   * produce «the card is not there» — a failed request, a request still in flight, a scope with no
   * rows, or a layout that never listed it — and a screenshot separates none of them. `data-strip-state`
   * puts the answer in the page snapshot every failure already attaches.
   *
   * Diagnostic, not behaviour: no test asserts it and nothing renders differently for it. It exists so
   * the NEXT sighting arrives with the one fact that would otherwise need a fourth reproduction.
   */
  if (error !== undefined && error !== null) {
    return (
      <section data-testid={`${id}-metrics`} data-strip-state="error" className="space-y-2">
        <QueryFailure
          error={error}
          ar={ar}
          onRetry={onRetry}
          fallbackTitle={t('loadFailed', ar)}
          testId={`${id}-metrics-failure`}
        />
      </section>
    )
  }

  /*
   * A request still in flight is not an answer. The skeleton holds the row's shape so the page does
   * not jump when the figures land, and says nothing about them.
   */
  if (loading) {
    return (
      <section data-testid={`${id}-metrics`} data-strip-state="loading" className="space-y-2">
        <div
          data-testid={`${id}-metrics-loading`}
          aria-busy="true"
          aria-label={t('loading', ar)}
          className={`grid grid-cols-2 ${CARD_GAP} lg:grid-cols-3 xl:grid-cols-4`}
        >
          {primary.map((item) => (
            <div key={item.key} className="h-[96px] animate-pulse rounded-2xl border border-border bg-surface-secondary/40" />
          ))}
        </div>
      </section>
    )
  }

  /*
   * An empty scope has no standing to describe a connector.
   *
   * Every card's absence is read from `reported`, which answers «which metric keys are present in
   * this scope» — so with no rows at all it answers every key false and fourteen cards say «لم
   * ترسله المنصة». That is a claim about Meta and Snapchat derived from an absence of CAMPAIGNS,
   * and it is what «تغيير الأهداف يجعل كل شيء فارغًا» looks like from the inside: not a broken
   * screen, a screen confidently saying something false.
   *
   * One true sentence about the filter replaces all of them.
   */
  if (hasRows === false) {
    return (
      <section data-testid={`${id}-metrics`} data-strip-state="empty-scope" className="space-y-2">
        {(comparisonLabel || note) && (
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-text-secondary">
            {comparisonLabel && <span data-testid={`${id}-metrics-comparison`}>{comparisonLabel}</span>}
            {note && <span data-testid={`${id}-metrics-note`}>{note}</span>}
          </div>
        )}
        <div
          data-testid={`${id}-metrics-empty-scope`}
          className="rounded-xl border border-dashed border-border bg-surface-secondary/40 px-4 py-6 text-center"
        >
          <p className="text-sm font-semibold text-text-primary">
            {ar ? 'لا توجد بيانات ضمن هذه الفلاتر' : 'No data matches these filters'}
          </p>
          <p className="mt-1 text-xs text-text-secondary">
            {ar
              ? 'جرّب توسيع الفترة أو تغيير الهدف أو المنصة. هذا ليس نقصًا في بيانات المنصة.'
              : 'Try widening the period, or changing the objective or platform. This is not a gap in the platform’s data.'}
          </p>
        </div>
      </section>
    )
  }

  return (
    <section data-testid={`${id}-metrics`} data-strip-state="rows" className="space-y-3">
      {(comparisonLabel || note) && (
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-text-secondary">
          {comparisonLabel && (
            <span data-testid={`${id}-metrics-comparison`}>
              {t('vs', ar)} {comparisonLabel}
            </span>
          )}
          {note && <span data-testid={`${id}-metrics-note`}>{note}</span>}
        </div>
      )}

      <div className={`grid grid-cols-2 ${CARD_GAP} lg:grid-cols-3 xl:grid-cols-4`}>
        {primary.map((item) => (
          <MetricCard key={item.key} item={item} ar={ar} />
        ))}
      </div>

      {secondary.length > 0 && (
        <>
          <button
            type="button"
            data-testid={`${id}-metrics-toggle`}
            aria-expanded={expanded}
            onClick={() => setExpanded((e) => !e)}
            className={`inline-flex items-center gap-1.5 rounded-full border border-border bg-surface-secondary/60 px-3 text-xs font-semibold text-text-secondary hover:bg-surface-hover hover:text-text-primary ${TOUCH_CONTROL}`}
          >
            {expanded ? <ChevronUp size={13} aria-hidden /> : <ChevronDown size={13} aria-hidden />}
            {expanded ? t('less', ar) : `${t('more', ar)} (${secondary.length})`}
          </button>

          {expanded && (
            <div
              data-testid={`${id}-metrics-secondary`}
              className={`grid grid-cols-2 ${CARD_GAP} lg:grid-cols-3 xl:grid-cols-4`}
            >
              {secondary.map((item) => (
                <MetricCard key={item.key} item={item} ar={ar} />
              ))}
            </div>
          )}
        </>
      )}
    </section>
  )
}

/**
 * A number as a reading — the one place `null` is allowed to meet a formatter.
 *
 * `value` is the raw figure and `format` turns it into text. A `null` never reaches `format`, so a
 * formatter that would have printed «0» for it cannot be handed the chance.
 */
export function reading(
  value: number | null | undefined,
  format: (n: number) => string,
  absent: 'no_data' | 'not_provided' = 'no_data',
): MetricReading {
  if (value === null || value === undefined) return { kind: absent }
  return { kind: 'value', text: format(value) }
}
