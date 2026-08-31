/**
 * ANALYTICS-TRUTH-002 — the daily series read through the same money contract as the cards above it.
 *
 * The page shipped two charts that contradicted the KPI strip directly above them:
 *
 *   «الإنفاق والنتائج والإيرادات» drew ONE line. Spend and revenue were withdrawn whenever the
 *   window's money was withheld, on the reasoning that a withheld day drawn at zero is a lie. That
 *   reasoning is right and the conclusion was wrong: the money is not missing, it is unconverted.
 *   The card overhead read «4,787.28 USD» from `spend_original` while the chart beneath it plotted
 *   nothing and kept a title promising three series.
 *
 *   «اتجاه CPA و ROAS و CTR» plotted `roas` and `cpa` straight off the row. Those are derived by the
 *   aggregator from a spend that was coalesced to 0, so they arrive null or zero for exactly the
 *   windows where the cards read 3.20x and 21.96 USD. CTR was named in the title and never plotted
 *   at all.
 *
 * So the fix is not to withdraw more, it is to read the same source the cards read. Every figure
 * here comes from {@link readMoney} / {@link readCostPer} / {@link readRoas} — this module contains
 * no money rule of its own, because a second copy of those rules is how the page drifted from the
 * dashboard in the first place.
 *
 * ## One currency for the whole series, or none
 *
 * A chart's Y axis carries a single unit. A series whose days are denominated differently cannot be
 * drawn on one axis without inventing a conversion, so `basis` reports `mixed` and the caller states
 * that rather than plotting it. This is the axis-level form of the rule `readMoney` applies per row.
 */
import { readCostPer, readMoney, readRoas, type MoneyFields } from '@/lib/money/contract'

/** A daily row as the timeseries endpoint returns it. */
export type SeriesRow = MoneyFields & {
  date: string
  conversions?: number | null
  clicks?: number | null
  impressions?: number | null
  cpa?: number | null
  ctr?: number | null
}

/** A row with every plotted figure resolved, so the chart reads fields and applies no rules. */
export type PlottedRow = {
  date: string
  spend: number | null
  revenue: number | null
  conversions: number | null
  roas: number | null
  cpa: number | null
  /** Stored as a percentage, because the axis is labelled in percent. */
  ctr: number | null
}

export type PlottedSeries = {
  rows: PlottedRow[]
  /** The unit every money figure above is expressed in. Null when there is no money to express. */
  currency: string | null
  /** `converted` — the project's own currency. `original` — the platform's, awaiting a rate.
   *  `mixed` — days disagree, so no single axis is honest. `none` — no money in this window. */
  basis: 'converted' | 'original' | 'mixed' | 'none'
  /** Set when `basis` is `original` or `mixed`: why the money is not in the reporting currency. */
  note: string | null
  /** Whether any day carries a money figure at all — a chart with none should say so, not draw flat. */
  hasMoney: boolean
}

const asNumber = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null)

export function plotSeries(rows: SeriesRow[], reportingCurrency: string | null, ar: boolean): PlottedSeries {
  const currencies = new Set<string>()
  let sawConverted = false
  let sawOriginal = false
  let note: string | null = null
  let hasMoney = false

  const plotted: PlottedRow[] = rows.map((row) => {
    const spend = readMoney(row, 'spend', reportingCurrency, ar)
    const revenue = readMoney(row, 'revenue', reportingCurrency, ar)
    const roas = readRoas(row, ar)
    const cpa = readCostPer(row, 'cpa', 'conversions', reportingCurrency, ar)

    for (const reading of [spend, revenue, cpa]) {
      if (reading.amount === null) continue
      hasMoney = true
      if (reading.currency !== null) currencies.add(reading.currency)
      if (reading.kind === 'withheld') {
        sawOriginal = true
        note ??= reading.note
      } else {
        sawConverted = true
      }
    }

    const impressions = asNumber(row.impressions) ?? 0
    const clicks = asNumber(row.clicks)

    return {
      date: row.date,
      spend: spend.amount,
      revenue: revenue.amount,
      conversions: asNumber(row.conversions),
      roas: roas.value,
      cpa: cpa.amount,
      /*
       * Recomputed rather than read off `row.ctr`, so the line cannot disagree with the counts on the
       * same row. It is a ratio of two counts and never depended on a rate — which is why it belongs
       * on this chart even in the windows where the money is unconvertible.
       */
      ctr: impressions > 0 && clicks !== null ? (clicks / impressions) * 100 : null,
    }
  })

  const mixed = currencies.size > 1 || (sawConverted && sawOriginal)

  return {
    rows: plotted,
    currency: mixed || currencies.size === 0 ? null : [...currencies][0],
    basis: !hasMoney ? 'none' : mixed ? 'mixed' : sawOriginal ? 'original' : 'converted',
    note: mixed ? (ar ? 'أيام هذه الفترة مُقوَّمة بعملات مختلفة، فلا يمكن رسمها على محور واحد' : 'Days in this period are denominated in different currencies, so one axis cannot state them') : sawOriginal ? note : null,
    hasMoney,
  }
}
