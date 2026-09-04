import { compact, money, moneyExact, num, percent, ratio } from '@/features/analytics/format'

/**
 * How a figure is written, in one place, for every surface that writes one.
 *
 * `MetricTable` has encoded this law since the table contract: a count is compact with the exact
 * figure a hover away, money goes through the money formatter, a percentage and a ratio are printed
 * whole because rounding them destroys the decision they exist for. That law was right and it was
 * reachable only by rendering a table.
 *
 * The KPI cards did not have it. They took a pre-formatted STRING from whichever surface built them,
 * so each surface chose — and they chose differently: the owner's own screenshot shows «29,210»,
 * «4,127,676» and «5.54K USD» in one row of six cards, three notations for one idea, on the page a
 * client reads. This is the shared answer, so a card and a table cell of the same metric cannot
 * disagree.
 *
 * ## What is compact and what is never compact
 *
 * Counts and money TOTALS are compact: they are read for scale, and «4.13M» is read faster and
 * remembered better than «4,127,676». Cost-per figures, ratios and percentages are NOT, and that is a
 * product rule rather than a style: CPC at «1.50 USD» is a decision, and «2 USD» is a different one.
 *
 * `cost` exists for exactly that. It was found by reading the rendered page rather than the code: a
 * CPC of about one and a half dollars printed «2 USD», because a cost-per was going through the same
 * `money()` every total goes through and `money()` compacts. `moneyExact` had already learnt this
 * lesson one order of magnitude up — it keeps the decimals on a small figure «because on a cost-per
 * the fraction IS the figure» — and the kinds now carry that distinction instead of every caller
 * remembering it.
 * The exact figure stays reachable for the compacted ones, and `exact` is null when compacting
 * changed nothing — a tooltip repeating what is already on screen teaches a reader to ignore
 * tooltips.
 */
export type MetricKind = 'text' | 'number' | 'money' | 'cost' | 'percent' | 'ratio'

export interface MetricValue {
  /** What is shown. Never null: a missing figure is the product's one dash. */
  text: string
  /** The un-abbreviated figure, or null when it would repeat `text`. */
  exact: string | null
  /** The number behind it, for sorting and comparison. Null when there is none. */
  value: number | null
}

/** The product's one dash: «nobody reported this», which is neither a zero nor a blank. */
const MISSING: MetricValue = { text: '—', exact: null, value: null }

export function readMetricValue(
  kind: MetricKind,
  raw: unknown,
  options: { currency?: string | null; digits?: number } = {},
): MetricValue {
  if (kind === 'text') {
    const text = raw === null || raw === undefined || raw === '' ? '—' : String(raw)

    return { text, exact: null, value: null }
  }

  const n = typeof raw === 'number'
    ? raw
    : raw === null || raw === undefined || raw === '' ? null : Number(raw)

  if (n === null || Number.isNaN(n)) return MISSING

  /*
   * A cost-per, a rate, an average: exact, always. Never compacted, and no separate «exact» because
   * what is shown already is it.
   */
  if (kind === 'cost') return { text: moneyExact(n, options.currency ?? null), exact: null, value: n }

  if (kind === 'money') {
    const currency = options.currency ?? null
    const shown = money(n, currency)
    const full = moneyExact(n, currency)

    return { text: shown, exact: shown === full ? null : full, value: n }
  }

  // Precision IS the decision here — see the note above.
  if (kind === 'percent') return { text: percent(n, options.digits ?? 1), exact: null, value: n }
  if (kind === 'ratio') return { text: ratio(n), exact: null, value: n }

  const shown = compact(n)
  const full = num(n)

  return { text: shown, exact: shown === full ? null : full, value: n }
}
