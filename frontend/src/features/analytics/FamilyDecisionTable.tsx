import { MetricTable, type SortValues } from '@/components/ui/MetricTable'
import { familyTotal, type FamilyRow } from './familyTotals'
import { compact, money as fmtMoney, percent, ratio } from './format'
import { moneyState, type MoneyTotals } from '@/lib/money/contract'
import type { Locale } from '@/stores/ui'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — the objective tab's decision surface.
 *
 * ## What the tab could not do
 *
 * Each family had a card of totals and a bar showing where its money sat. Both answer «how is this
 * objective doing». Neither answers the question an operator opens the tab to settle: of the eight
 * sales campaigns, which one is worth more money next week. That is a comparison BETWEEN rows on a
 * column the reader chooses, and a `<dl>` of family totals cannot be one however good the totals
 * are.
 *
 * ## Per family, which is what makes it safe
 *
 * One table inside each family, never one table across families. That is not a layout preference —
 * it is the requirement's central rule expressed structurally: an awareness campaign and a sales
 * campaign share no metric that means the same thing in both, so a single sortable list of all of
 * them would be a ranking whose top row is decided by which objective happens to produce the bigger
 * number. There is no such list here to sort.
 *
 * The columns are the family's own, read from `layoutFor` through the caller, so a family is judged
 * on what it was bought for and a metric added to an objective appears here without anybody
 * remembering this file exists.
 *
 * ## Money keeps the contract
 *
 * A cell whose scope is partial, mixed-currency or withheld reads «—» and SORTS LAST rather than as
 * a zero. A campaign whose spend is awaiting a rate is not the cheapest campaign, and a sort that
 * said so would be the money contract undone by a click.
 */
export function FamilyDecisionTable({
  family,
  campaigns,
  kpis,
  currency,
  locale,
  specs,
}: {
  family: string
  campaigns: FamilyRow[]
  /** The metrics this family is judged by — `layoutFor(family).primary`. */
  kpis: string[]
  currency: string | null
  locale: Locale
  /** The catalogue's label and formatter per key, passed in so this file holds no second copy. */
  specs: Record<string, { label: { ar: string; en: string }; format: (n: number) => string } | undefined>
}) {
  const ar = locale === 'ar'

  /*
   * Fewer than two campaigns is not a comparison.
   *
   * A one-row sortable table invites a reader to rank something against nothing, and the header
   * controls promise an answer the data cannot give. The family card above already states the
   * totals, so the honest output here is nothing at all.
   */
  if (campaigns.length < 2) {
    return null
  }

  const nameOf = (row: FamilyRow): string =>
    String(row.campaign_name ?? row.campaign_id ?? '—')

  /** One row's value for a metric, through the same rules the family totals use. */
  const valueFor = (row: FamilyRow, key: string): number | null => familyTotal([row], key)

  const isMoney = (key: string): boolean => key === 'spend' || key === 'revenue'

  const readable = (row: FamilyRow, key: string): boolean => {
    if (!isMoney(key)) return true

    // The contract's own verdict: only a complete, converted figure may be printed as a number.
    return moneyState(row as MoneyTotals, key as 'spend' | 'revenue').state === 'complete_converted'
  }

  const text = (row: FamilyRow, key: string): string => {
    const value = valueFor(row, key)

    if (value === null || !readable(row, key)) return '—'
    if (isMoney(key)) return fmtMoney(value, currency)
    if (key === 'roas') return ratio(value)
    if (key === 'ctr' || key === 'conversion_rate' || key === 'view_rate' || key === 'completion_rate') {
      return percent(value)
    }

    const spec = specs[key]

    return spec ? spec.format(value) : compact(value)
  }

  const head = [
    ar ? 'الحملة' : 'Campaign',
    ...kpis.map((k) => {
      const spec = specs[k]

      return spec ? (ar ? spec.label.ar : spec.label.en) : k
    }),
  ]

  const rows = campaigns.map((row) => [
    <span key="n" className="block max-w-[220px] truncate font-semibold text-text-primary" title={nameOf(row)}>
      {nameOf(row)}
    </span>,
    ...kpis.map((k) => <span key={k} dir="ltr">{text(row, k)}</span>),
  ])

  const values = campaigns.map((row): SortValues => [
    nameOf(row),
    // A figure the table refused to print may not order it either.
    ...kpis.map((k) => (readable(row, k) ? valueFor(row, k) : null)),
  ])

  /* NUMBER-PRESENTATION-001 — the exact figure behind an abbreviation, where one was made. */
  const exact = campaigns.map((row) => [
    null,
    ...kpis.map((k) => {
      const value = valueFor(row, k)

      if (value === null || !readable(row, k) || isMoney(k)) return null

      const whole = value.toLocaleString('en-US', { maximumFractionDigits: 2 })

      return text(row, k) === whole ? null : whole
    }),
  ])

  return (
    <div className="mt-3" data-testid={`objective-decision-${family}`}>
      <MetricTable
        head={head}
        rows={rows}
        values={values}
        exact={exact}
        /* Spend first: «where is the money» is the question that precedes every other one here. */
        initialSort={{ column: 1, dir: 'desc' }}
      />
    </div>
  )
}
