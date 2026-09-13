import { SPECS, layoutFor } from './metricCatalog'
import { metricLabel } from './metricLabels'

/**
 * OBJECTIVE-ANALYTICS-DEPTH-001 — which metric columns an entity table may show.
 *
 * ## The defect
 *
 * The ad-set and ad tables rendered a FIXED column set — spend, impressions, reach, frequency,
 * clicks, CTR, CPC, CPM, results, CPA — for every row whatever its campaign was bought for. So a
 * sales ad set was shown frequency and CPM and denied ROAS, and an awareness ad set was shown a cost
 * per order it was never bought to produce. `ObjectiveFamily::headlineMetrics()` exists to prevent
 * exactly that, and it could not reach this grain: the rows carried no objective to select on until
 * `EntityMetricsAggregator` began stating one.
 *
 * ## The rule, which is the KPI row's rule and not a second one
 *
 * `layoutFor` already decides this for the headline row: ONE family in scope gets that family's
 * layout, and two or more fall to `MIXED_LAYOUT`, which withholds cost-per and return because «a CPA
 * spanning a brand budget and a sales budget divides one objective's money by another objective's
 * events». That reasoning is the same one rung down, so this calls it rather than restating it.
 *
 * Two things this adds on top, both because a TABLE is not a KPI row:
 *
 *   SPEND LEADS, ALWAYS. Every layout carries spend, but in `secondary` — for a headline row that is
 *   right, because what the money bought is the answer and what it cost is the question. In a table
 *   an operator scans down one column, and spend is the operational fact they are scanning for. It is
 *   pinned first and never repeated further along.
 *
 *   ONLY WHAT THIS GRAIN REPORTS. The awareness layout names `video_completions` and
 *   `video_completion_rate`; `EntityMetricsAggregator` emits `video_p100` and `completion_rate`.
 *   Rendering layout keys blind would have produced two permanently empty columns — a fresh
 *   false-absence defect, of the kind this row's whole family is about. So the layout is intersected
 *   with what the endpoint actually fills, and `entityColumnsAreServed` asserts the served list stays
 *   true as either side changes.
 */

/**
 * Every metric key `EntityMetricsAggregator` puts on a row — sums, derived ratios and the averaged
 * frequency. Not the money-truth companions: those are read THROUGH the money contract by the spend
 * and revenue cells rather than shown as columns of their own.
 */
export const ENTITY_SERVED_METRICS: readonly string[] = [
  // summed
  'impressions', 'reach', 'clicks', 'landing_page_views', 'engagements',
  'video_views', 'video_p25', 'video_p50', 'video_p75', 'video_p100',
  'conversions', 'purchases', 'add_to_cart', 'checkout',
  'leads', 'sign_ups', 'installs', 'app_opens', 'page_views',
  'spend', 'revenue',
  // averaged
  'frequency',
  // derived
  'ctr', 'cpc', 'cpm', 'cpa', 'cpl', 'cpi', 'cpe',
  'cost_per_view', 'cost_per_lpv', 'roas', 'aov',
  'conversion_rate', 'engagement_rate', 'completion_rate', 'view_rate',
]

/** How many metric columns a table may carry before it stops being readable. */
const MAX_METRIC_COLUMNS = 9

/**
 * The distinct objective families present in a set of rows.
 *
 * A row whose objective is null is NOT a family and is not counted as one: `EntityMetricsAggregator`
 * returns null for an entity with no unified campaign, and that is the absence of an answer rather
 * than the `unknown` family, which has its own metrics. Counting it would turn one real family plus
 * one unlinked ad set into «mixed» and withhold the family's own figures for no reason the reader
 * could see.
 */
export function familiesIn(rows: ReadonlyArray<{ objective?: string | null }>): string[] {
  return [...new Set(rows.map((r) => r.objective).filter((o): o is string => typeof o === 'string' && o !== ''))].sort()
}

export type EntityColumnPlan = {
  /** The metric keys to show, in order, spend first. */
  keys: string[]
  /** The single family the columns follow, or null when the rows span several (or name none). */
  family: string | null
  /** True when the rows hold more than one family, so no one KPI set is meaningful for all of them. */
  mixed: boolean
}

/**
 * The metric columns for these rows.
 *
 * `mixed` is stated rather than inferred from `family === null`, because those are different facts: no
 * family at all (nothing linked, or an empty table) is not the same as several, and only the second
 * one owes the reader a sentence explaining why the objective's own figures are absent.
 */
export function entityColumnPlan(rows: ReadonlyArray<{ objective?: string | null }>): EntityColumnPlan {
  const families = familiesIn(rows)
  const layout = layoutFor('all', families)
  const served = new Set(ENTITY_SERVED_METRICS)

  const ordered = ['spend', ...[...layout.primary, ...layout.secondary].filter((k) => k !== 'spend')]
  const keys = [...new Set(ordered)].filter((k) => served.has(k)).slice(0, MAX_METRIC_COLUMNS)

  return {
    keys,
    family: families.length === 1 ? families[0] : null,
    mixed: families.length > 1,
  }
}

/**
 * What each cost-per figure is divided BY, so a cell can say «—» instead of a number over nothing.
 *
 * `rowCostPer` needs the denominator to answer honestly: a cost per order on an ad set with no orders
 * is not a large number, it is not a figure at all. Written out per key rather than guessed from the
 * name, because `cpm` is per THOUSAND impressions and `cost_per_lpv` reads a field whose name shares
 * no stem with it — two cases a convention would get wrong in opposite directions.
 *
 * A key absent here is not a cost-per and renders through its own spec.
 */
export const COST_PER_DENOMINATOR: Record<string, { field: string; per?: number }> = {
  cpc: { field: 'clicks' },
  cpm: { field: 'impressions', per: 1000 },
  cpa: { field: 'conversions' },
  cpl: { field: 'leads' },
  cpi: { field: 'installs' },
  cpe: { field: 'engagements' },
  cost_per_view: { field: 'video_views' },
  cost_per_lpv: { field: 'landing_page_views' },
}

/** The money keys, which go through the money contract rather than a plain formatter. */
export const ENTITY_MONEY_KEYS: readonly string[] = ['spend', 'revenue', 'aov']

/** The rate keys, rendered as percentages. */
export const ENTITY_RATE_KEYS: readonly string[] = [
  'ctr', 'conversion_rate', 'engagement_rate', 'completion_rate', 'view_rate',
]

/**
 * A column heading, from the catalogue that actually has one.
 *
 * `metricLabel` falls back to the RAW KEY when `METRIC_LABELS` has no entry, and that map does not
 * carry `roas`, `cpa`, `ctr`, `cpm`, `aov`, `cpl`, `cpi` or `cpe` — every cost-per and the return. So
 * the first version of this table would have printed a lowercase `roas` as a column heading, which is
 * the raw-key-on-screen defect this product has already had to fix once.
 *
 * `SPECS` is the richer catalogue and has all of them with both languages. `metricLabel` stays as the
 * fallback for keys only it knows, and `entityColumnsAreLabelled` fails if any key the plan can offer
 * reaches the raw-key floor — so adding a metric to a layout cannot quietly ship its own key as a
 * heading.
 */
export function entityColumnLabel(key: string, ar: boolean): string {
  const spec = SPECS[key]
  if (spec) {
    return ar ? spec.label.ar : spec.label.en
  }

  return metricLabel(key, ar)
}

/** Whether this key has a real label rather than falling through to its own name. */
export function entityColumnIsLabelled(key: string): boolean {
  return SPECS[key] !== undefined || metricLabel(key, false) !== key
}
