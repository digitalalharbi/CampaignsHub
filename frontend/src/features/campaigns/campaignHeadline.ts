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

export function campaignHeadline(
  objective: string | null,
  row: Record<string, unknown> | undefined,
  ar: boolean,
): CampaignHeadline | null {
  if (row === undefined) return null

  /*
   * The layout is asked for the campaign's own objective — through the canonical bucket, so a
   * `video_views` campaign is headlined like the attention buy it is rather than falling to the
   * mixed row that exists for scopes spanning several objectives.
   */
  const canonical = objective === null ? null : canonicalOfRaw(objective)
  /*
   * An objective the taxonomy does not recognise falls to the mixed row, which is the honest answer
   * for it — a headline chosen for an objective we cannot name would be a guess about what this
   * money was for.
   */
  const key = layoutFor(canonical ?? 'all').primary[0]
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
  const reported = { [key]: map?.[key] === true }

  return {
    key,
    label: ar ? spec.label.ar : spec.label.en,
    reading: readMetric(key, spec, row as Record<string, number | null>, reported),
  }
}
