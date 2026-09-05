import type { ReactNode } from 'react'
import { useUi } from '@/stores/ui'
import { CARD_GAP, CARD_PAD, METRIC_HINT, METRIC_LABEL, METRIC_VALUE_DENSE } from '@/styles/scale'

/**
 * UX-KPI-PRESENTATION-001 — one card, and one grid, for every figure the product leads with.
 *
 * ## What this replaces
 *
 * Nine components drew a labelled number: `SummaryCard` twice (tasks, alerts), `StatCard` twice
 * (campaigns, the client portal), `Kpi` three times (clients, finance, the interactive report),
 * `KpiCard` and `MiniStat` in the command centre. They agree on the idea and on nothing else —
 * `p-3.5` against `p-4`, `text-sm` labels against `text-xs`, some giving the value `dir="ltr"` and
 * some not — so a customer moving between two pages of the same product meets two designs of the
 * same object, and a row of cards drawn by two of them does not line up.
 *
 * ## The direction rule, which is the one that was actually wrong
 *
 * A number is written left-to-right in every language. In an Arabic layout an unmarked figure with a
 * suffix — «1.2K SAR», «-12%» — is reordered by the bidi algorithm and can render as «SAR 1.2K» or
 * move its sign to the wrong end. So the value is ALWAYS `dir="ltr"`.
 *
 * That is not the same as aligning it left. `text-start` keeps it under its own label, at the side
 * the reader starts from, which is the right edge in Arabic — the two settings are independent and
 * conflating them is how a card ends up with its label on one side and its number on the other.
 *
 * ## Heights
 *
 * A fixed padding scale and a `min-h` on the value AND hint rows, so a card with a hint and one without
 * are the same height. Cards of different heights in one row read as a layout accident, and an
 * operator scanning a row of them loses the alignment that makes scanning work.
 */
export type StatTone = 'neutral' | 'brand' | 'info' | 'success' | 'warning' | 'danger'

const DOT: Record<StatTone, string> = {
  neutral: 'bg-border',
  brand: 'bg-brand-500',
  info: 'bg-info',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
}

const VALUE_TONE: Record<StatTone, string> = {
  neutral: 'text-text-primary',
  brand: 'text-text-primary',
  info: 'text-text-primary',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-danger',
}

/**
 * The sparkline row's height, reserved whether or not a chart draws in it.
 *
 * `h-9` matches what the spark itself renders at, so a card that HAS one is unchanged and a card
 * that cannot have one stops standing shorter than its neighbours.
 */
/*
 * The sparkline row's height, reserved whether or not a chart draws in it.
 *
 * `h-9` matches what the spark itself renders at, so a card that HAS one is unchanged and a card
 * that cannot have one stops standing shorter than its neighbours. `min-w-0` lets the row shrink, so
 * a chart inside it can never widen the card that holds it.
 */
const SPARK_ROW = 'h-9 min-w-0'

export function StatCard({
  label,
  value,
  exact,
  hint,
  tone = 'neutral',
  dot = false,
  trailing,
  spark,
  shape = 'auto',
  testid,
}: {
  /**
   * The name of the figure.
   *
   * A node rather than a string: the finance and invoice surfaces put a small icon in front of the
   * name, and that was one of the reasons each kept its own card. The card still owns the SIZE and
   * the colour of the label — what a surface may bring is a mark, not a type decision.
   */
  label: ReactNode
  /** Already formatted — this component never decides how a number reads. */
  value: ReactNode
  /**
   * The full figure, when `value` abbreviated it — NUMBER-PRESENTATION-001.
   *
   * Rendered as a `title`, which is the one hover that also reaches a screen reader and survives
   * being printed.
   */
  exact?: string
  hint?: string
  tone?: StatTone
  /** The coloured dot some surfaces use to carry the tone next to the label. */
  dot?: boolean
  /** A delta pill, a link, an icon — whatever the surface puts beside the label. */
  trailing?: ReactNode
  /**
   * A sparkline, drawn by the surface that has the series.
   *
   * A SLOT rather than a chart: this component decides how a labelled figure looks and nothing else.
   * Two surfaces drew their own card because they needed a spark under the number, and the copy came
   * with its own label size, its own value size and its own padding — the spark was the reason, and
   * the drift was the cost.
   */
  spark?: ReactNode
  /**
   * The card's proportions — ANALYTICS-DIFFERENTIATION-001.
   *
   * `auto` is the KPI row's card: as tall as its contents, sitting in a row of its equals. `square`
   * is the analytical grid's focused signal — one figure, one meaning, in a tile that reads as a unit
   * beside a wide comparison rather than as one more entry in a row of six.
   *
   * It is a prop rather than a second component on purpose. The product has ONE card: the label
   * scale, the value's direction handling, the exact-figure title and the tone palette are decided
   * here and nowhere else, and a surface that wanted a different SHAPE was previously a surface that
   * hand-built a whole card to get one. What Analytics may choose is its grid; what it may not
   * choose is what a labelled figure looks like.
   */
  shape?: 'auto' | 'square'
  testid?: string
}) {
  return (
    <div
      data-testid={testid}
      /*
       * NOT `h-full`, and that is load-bearing rather than an omission.
       *
       * Adding it to make the card «fill its cell» made `/app/dashboard` scroll sideways by 26px on
       * firefox at every viewport — chromium and webkit were clean, which is why it reached CI.
       * Isolated by reverting this one file: all three browsers turned green, and removing `h-full`
       * alone reproduced that.
       *
       * It was also unnecessary. All four rows below are reserved — label, value, hint and spark —
       * so every card is already the same height by construction, which is what `h-full` was reached
       * for. Measured after removing it: six cards at one height, 124 at 390 and 132 at 1440.
       */
      className={`flex flex-col gap-1.5 rounded-2xl border border-border bg-surface ${CARD_PAD} shadow-[var(--shadow-small)] ${
        shape === 'square' ? 'aspect-square justify-between' : ''
      }`}
    >
      {/*
        RESERVED, like the value and hint rows below it — a card whose movement pill is absent had a
        16px label row against a 24px one, and after the hint row was equalised this was the eight
        pixels still separating «نشطة» from «CPA» at 390 on the campaigns page. A movement pill is
        present exactly when a comparison window produced one, so on any real KPI row some cards
        carry it and some do not.
      */}
      <div className="flex min-h-6 items-center justify-between gap-2">
        <span className={`flex items-center gap-1.5 text-text-secondary ${METRIC_LABEL}`}>
          {dot && <span className={`h-2 w-2 shrink-0 rounded-full ${DOT[tone]}`} aria-hidden />}
          {label}
        </span>
        {trailing}
      </div>

      <span
        dir="ltr"
        title={exact}
        data-testid={testid ? `${testid}-value` : undefined}
        className={`min-h-9 text-start ${METRIC_VALUE_DENSE} ${VALUE_TONE[tone]}`}
      >
        {value}
      </span>

      {/*
        RESERVED whether or not it draws — the same rule `MetricStrip` follows for its chart row.
        `min-h-9` above equalises the VALUE row, which is what the card's own note claimed made a
        hinted and an unhinted card the same height. It does not: the hint is a second row, and a
        card carrying one is eighteen pixels taller. At 1440 a taller sibling stretches the shorter
        one and the difference is invisible; at 390 the same six cards sit in three rows of two, and
        the campaigns page measured 115, 115 and 100 — a grid whose rhythm breaks on the last row.
        Empty rather than a non-breaking space, so nothing is announced where there is nothing.
      */}
      <span aria-hidden={!hint} className={`min-h-[1.125rem] text-text-muted ${METRIC_HINT}`}>{hint}</span>

      {/*
        RESERVED, and the last of the four rows to be — measured on PRODUCTION after the other three
        were fixed: the shared report's six KPI cards stood at 174, 174, 174, 174, 132, 132, because
        the top row's metrics had a sparkline and the bottom row's did not. A sparkline is present
        exactly when a metric has a valid series behind it, so on any real KPI row some cards carry
        one and some cannot — which makes this the row most likely to differ, not the least.

        `MetricStrip` has reserved its chart row from the start. This card did not, and that is the
        whole difference between the two components' geometry.
      */}
      <div className={`mt-auto ${SPARK_ROW}`} aria-hidden={!spark}>{spark}</div>
    </div>
  )
}

/**
 * The grid they sit in.
 *
 * `auto-fit` with a minimum width rather than a fixed column count: five KPIs in a four-column grid
 * leave one card alone on a row looking like a mistake, and the requirement asks for additional KPIs
 * to read as designed rather than as leftovers. With `auto-fit` the cards share the row they have.
 *
 * The minimum is what stops them colliding: below it the grid drops a column instead of squeezing a
 * number until it wraps mid-figure.
 */
export function StatGrid({ children, min = '11.5rem' }: { children: ReactNode; min?: string }) {
  const ar = useUi((s) => s.locale) === 'ar'

  return (
    <div
      dir={ar ? 'rtl' : 'ltr'}
      className={`grid ${CARD_GAP}`}
      style={{ gridTemplateColumns: `repeat(auto-fit, minmax(min(${min}, 100%), 1fr))` }}
    >
      {children}
    </div>
  )
}
