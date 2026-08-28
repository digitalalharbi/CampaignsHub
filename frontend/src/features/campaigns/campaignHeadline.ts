import { SPECS, layoutFor, readMetric } from '@/features/analytics/metricCatalog'
import type { MetricReading } from '@/components/ui/MetricStrip'
import { canonicalOfRaw } from './canonicalObjectives'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the one result a campaign row leads with.
 *
 * The card showed a budget and a count of linked platform campaigns: two numbers that say nothing
 * about whether the money is working. An operator deciding whether to keep paying for a campaign
 * needs what it spent and what it produced — and «what it produced» is a different metric for a
 * leads campaign than for a sales one.
 *
 * Both halves are borrowed rather than reinvented. `layoutFor` picks the metric, from the same
 * catalogue the dashboard and Analytics headline with; `readMetric` reads it, with the same rules
 * about withheld money and unreported keys. A second definition of «this campaign's result» would
 * drift from the headline above it, and the two would disagree about the same campaign.
 *
 * **No opaque health score.** The card states a named figure. A number a reader cannot trace back to
 * a metric is a verdict dressed as a measurement.
 */
export interface CampaignHeadline {
  key: string
  label: string
  reading: MetricReading
}

/** The headline row this campaign's objective is judged on — the shared catalogue, asked once. */
function headlineKeys(objective: string | null): string[] {
  const canonical = objective === null ? null : canonicalOfRaw(objective)

  /*
   * An objective the taxonomy does not recognise falls to the mixed row, which is the honest answer
   * for it — a headline chosen for an objective we cannot name would be a guess about what this
   * money was for.
   */
  return layoutFor(canonical ?? 'all').primary
}

function reading(key: string, row: Record<string, unknown>, ar: boolean): CampaignHeadline | null {
  const spec = SPECS[key]
  if (spec === undefined) return null

  /*
   * A key is reported only when the row SAYS it was — `=== true`, not «not false».
   *
   * `byCampaign()` sums with COALESCE, so every key nobody sent arrives as 0. An absent map, or a
   * key missing from one, is an unanswered question, and answering it «reported» would print
   * «العملاء المحتملون 0» for a campaign whose connector has never sent a lead: the absence of a
   * measurement rendered as a failure, on the screen where somebody decides to stop paying for it.
   */
  const map = row.reported as Record<string, boolean> | undefined

  return {
    key,
    label: ar ? spec.label.ar : spec.label.en,
    reading: readMetric(key, spec, row as Record<string, number | null>, { [key]: map?.[key] === true }),
  }
}

export function campaignHeadline(
  objective: string | null,
  row: Record<string, unknown> | undefined,
  ar: boolean,
): CampaignHeadline | null {
  if (row === undefined) return null

  const key = headlineKeys(objective)[0]

  return key === undefined ? null : reading(key, row, ar)
}

/**
 * What that result COST — the objective's own cost-per.
 *
 * A result on its own does not decide anything: forty orders is good or bad depending on what was
 * paid for them. The metric is FOUND rather than mapped — the first key in the objective's headline
 * row that the catalogue marks `invertGood`, which is exactly the property «lower is better» that
 * makes a metric a cost. A second hand-written objective→cost map would be a fourth place the
 * taxonomy lives, and the first new objective would put it out of step with the other three.
 *
 * `readMetric` refuses a cost with nothing to divide: a campaign that spent money and produced no
 * orders has no cost per order, and printing one would invent it.
 */
export function campaignEfficiency(
  objective: string | null,
  row: Record<string, unknown> | undefined,
  ar: boolean,
): CampaignHeadline | null {
  if (row === undefined) return null

  const key = headlineKeys(objective).find((k) => SPECS[k]?.invertGood === true)

  return key === undefined ? null : reading(key, row, ar)
}
