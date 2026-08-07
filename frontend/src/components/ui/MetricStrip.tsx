import { useId, useState } from 'react'
import { Area, AreaChart, ResponsiveContainer } from 'recharts'
import { ArrowDownRight, ArrowUpRight, ChevronDown, ChevronUp, Info, Minus } from 'lucide-react'

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
  /** A real, measured figure — already formatted, including a real zero. */
  | { kind: 'value'; text: string }
  /** The platform does not report this metric. */
  | { kind: 'not_provided' }
  /** Reported, but nothing for this window — or a ratio whose denominator is missing. */
  | { kind: 'no_data' }

export type MetricItem = {
  key: string
  label: string
  reading: MetricReading
  /** Change against the comparison window, as a ratio (0.12 = +12%). `null` when incomparable. */
  delta?: number | null
  /** True where DOWN is the good direction — cost per result, CPC, CPA. */
  invertGood?: boolean
  /** What this metric means, in one sentence. Shown on hover and on focus. */
  hint?: string
  spark?: number[]
  /** Draws the eye to the metric the objective is actually judged on. */
  lead?: boolean
}

const T = {
  notProvided: { ar: 'لم ترسله المنصة', en: 'Not provided' },
  noData: { ar: 'لا توجد بيانات', en: 'No data' },
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
        className="rounded-full p-0.5 text-text-muted hover:text-text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40"
      >
        <Info size={13} aria-hidden />
      </button>
      {open && (
        <span
          role="tooltip"
          id={id}
          className="absolute top-full z-40 mt-1 w-56 rounded-xl border border-border-strong bg-surface p-2 text-xs font-normal leading-relaxed text-text-secondary shadow-[var(--shadow-medium)] ltr:left-0 rtl:right-0"
        >
          {text}
        </span>
      )}
    </span>
  )
}

function Delta({ delta, invertGood, ar }: { delta: number; invertGood?: boolean; ar: boolean }) {
  const flat = Math.abs(delta) < 0.0005
  const up = delta > 0
  const good = flat ? null : invertGood ? !up : up
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
  const missing = item.reading.kind !== 'value'
  const missingText =
    item.reading.kind === 'not_provided' ? t('notProvided', ar) : t('noData', ar)
  const missingHint =
    item.reading.kind === 'not_provided' ? t('notProvidedHint', ar) : t('noDataHint', ar)

  return (
    <div
      data-testid={`metric-${item.key}`}
      data-state={item.reading.kind}
      className={`flex flex-col gap-1.5 rounded-2xl border bg-surface p-3 ${
        item.lead ? 'border-brand-500/50 shadow-[var(--shadow-small)]' : 'border-border'
      }`}
    >
      <div className="flex items-start justify-between gap-1">
        <span className="inline-flex items-center gap-1 text-xs font-semibold text-text-secondary">
          {item.label}
          {item.hint && <InfoHint text={item.hint} label={`${t('definition', ar)}: ${item.label}`} />}
        </span>
        {/*
          A delta only where there is a figure to compare. «+12%» beside «Not provided» would be a
          comparison of two absences, printed as a change.
        */}
        {!missing && item.delta !== null && item.delta !== undefined && (
          <Delta delta={item.delta} invertGood={item.invertGood} ar={ar} />
        )}
      </div>

      {item.reading.kind === 'value' ? (
        <span dir="ltr" className="tnum text-2xl font-extrabold leading-none tracking-tight text-text-primary">
          {item.reading.text}
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
}) {
  const [expanded, setExpanded] = useState(false)

  return (
    <section data-testid={`${id}-metrics`} className="space-y-2">
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

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
            className="inline-flex items-center gap-1 rounded-lg px-1 py-1 text-xs font-semibold text-text-secondary underline underline-offset-2 hover:text-text-primary"
          >
            {expanded ? <ChevronUp size={13} aria-hidden /> : <ChevronDown size={13} aria-hidden />}
            {expanded ? t('less', ar) : `${t('more', ar)} (${secondary.length})`}
          </button>

          {expanded && (
            <div
              data-testid={`${id}-metrics-secondary`}
              className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
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
