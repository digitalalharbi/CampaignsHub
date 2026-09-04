/**
 * CLIENT-FACING-PRESENTATION-001 + ANALYTICS-OBJECTIVE-SYSTEM-001 — which figures a client's KPI
 * block actually shows.
 *
 * The block was a fixed list of eight: spend, impressions, clicks, results, add-to-cart, purchases,
 * revenue, ROAS. Two things were wrong with it, and both are visible on the owner's own link.
 *
 * **It printed the same number twice.** «Results 581» and «Purchases 581» — for a sales report the
 * result IS the purchase, so a client reads two cards with different names and one figure, and has
 * to work out for themselves that they agree.
 *
 * **It never showed what a result COST.** `cpa` is defined right beside the other cards and was in
 * no default set, so the one figure the hard rule names first — «ROAS/CPL/CPA/CPC/CPM as objective
 * relevant» — was missing from the executive block of every client link, while add-to-cart, a stage
 * that means nothing to a lead-generation account, was always present.
 *
 * ## What decides instead
 *
 * The server already answers this. `objective_performance.paths` carries, per marketing path, the
 * `headline_metrics` that path is judged on — the product's ONE definition of which metrics matter
 * for awareness against traffic against sales. This reads that answer rather than adding a second.
 *
 * A report whose spend sits on ONE path is shown that path's metrics. A report spanning several is
 * shown the cross-objective set and NO cost-per figure, because a CPA averaged over a brand campaign
 * and a sales campaign is the blend `resultModel` refuses everywhere else in the product: it makes
 * the brand campaign look expensive at a job it was never bought to do.
 */

/** Paths whose spend is zero are not what this report is about, whatever the account also runs. */
interface Path {
  spend?: number | null
  headline_metrics?: string[] | null
}

export interface KpiSource {
  /** The operator's own selection. When set it wins — LIVEREP-002. */
  metrics?: string[] | null
  objective_performance?: { paths?: Path[] | null } | null
  totals?: Record<string, unknown> | null
}

/**
 * Safe across objectives: counts and a return, no cost-per.
 *
 * `conversions` stays because «how many results» is answerable for a mixed programme; the cost of one
 * is not, and neither is a CPM over campaigns half of which never wanted impressions.
 */
export const CROSS_OBJECTIVE = ['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'roas']

/**
 * A metric that is another metric's own name on this path.
 *
 * `purchases` is what the sales path calls a conversion. Both are true, and printing both puts one
 * figure on the page twice — so the alias is dropped when the two totals agree, and KEPT when they do
 * not, because then they are genuinely two different counts and a reader needs both.
 */
const ALIASES: Array<[string, string]> = [['purchases', 'conversions']]

/**
 * The path's word for a metric, and the card's.
 *
 * `objective_performance` calls the sales result an ORDER; the totals and the KPI card call it a
 * conversion. Both names are right in their own place, and the seam between them is invisible until
 * something reads one list against the other — which is exactly what this does. Left untranslated,
 * following the objective DROPPED the result count from a sales report: the block showed spend, cost
 * per result, revenue and ROAS, and never once said how many orders there were.
 */
const CARD_FOR: Record<string, string> = { orders: 'conversions' }

export function clientKpiKeys(source: KpiSource, renderable: ReadonlySet<string>): string[] {
  if ((source.metrics?.length ?? 0) > 0) return source.metrics as string[]

  const spending = (source.objective_performance?.paths ?? []).filter((p) => (p.spend ?? 0) > 0)

  const chosen = spending.length === 1 && (spending[0].headline_metrics?.length ?? 0) > 0
    ? (spending[0].headline_metrics as string[])
    : CROSS_OBJECTIVE

  const named = chosen.map((key) => CARD_FOR[key] ?? key)

  // Spend leads every block: «what did this cost me» is the first question, whatever the objective.
  const ordered = ['spend', ...named.filter((key) => key !== 'spend')]

  const keys = ordered.filter((key) => renderable.has(key))
  const totals = source.totals ?? {}

  return keys.filter((key) => {
    const alias = ALIASES.find(([name]) => name === key)

    if (alias === undefined) return true

    const [, canonical] = alias
    const same = totals[key] !== null && totals[key] !== undefined && totals[key] === totals[canonical]

    return !(same && keys.includes(canonical))
  })
}
