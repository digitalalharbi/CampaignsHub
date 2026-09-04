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
 * A fixed padding scale and a `min-h` on the value row, so a card with a hint and a card without one
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

export function StatCard({
  label,
  value,
  exact,
  hint,
  tone = 'neutral',
  dot = false,
  trailing,
  spark,
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
  testid?: string
}) {
  return (
    <div
      data-testid={testid}
      className={`flex flex-col gap-1.5 rounded-2xl border border-border bg-surface ${CARD_PAD} shadow-[var(--shadow-small)]`}
    >
      <div className="flex items-center justify-between gap-2">
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

      {hint && <span className={`text-text-muted ${METRIC_HINT}`}>{hint}</span>}

      {spark}
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
