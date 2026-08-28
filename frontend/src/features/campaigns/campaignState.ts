import { conciseFinding, type ConciseFinding } from '@/features/analytics/conciseFinding'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the concise state on a campaign row, from the SAME engine.
 *
 * The requirement asks the workspace to say what a campaign's state is and what to do about it. It
 * also says, in capitals, **no arbitrary opaque health score** — and a score is exactly what this
 * becomes if the row grows its own rules. A number between 0 and 100 with nothing behind it is the
 * most confident-looking thing a product can print, and the least answerable.
 *
 * So a row's state is `diagnose()` run over that row's own totals, through the same chooser the
 * dashboard headline uses. Three surfaces, one engine, and when they disagree it is because the data
 * differs — never because the rules do.
 *
 * ## The row's totals are per campaign, and its `reported` map is too
 *
 * `byCampaign()` coalesces every unreported metric to 0, and `reportedKeysByCampaign()` is what tells
 * them apart. Passing the row's own map through is what stops «this campaign is not delivering» being
 * printed for a campaign whose connector never sent impressions.
 */
export interface CampaignState {
  finding: ConciseFinding | null
  /** Null when this row cannot be judged at all — no metrics, or nothing reported. */
  judged: boolean
}

export function campaignState(
  objective: string | null,
  row: Record<string, unknown> | undefined,
): CampaignState {
  if (row === undefined) {
    // No metrics row at all. Not «healthy» — unjudged, and the caller must render the difference.
    return { finding: null, judged: false }
  }

  const reported = (row.reported as Record<string, boolean> | undefined) ?? {}

  const totals: Record<string, number | null | undefined> = {}
  for (const [key, value] of Object.entries(row)) {
    if (typeof value === 'number') totals[key] = value
  }

  const finding = conciseFinding({ objective, totals, reported })

  /*
   * «Judged» is not «a weakness was found». A campaign the engine examined and found healthy is
   * judged; one it could not examine is not, and the row may not show the same thing for both.
   */
  return { finding, judged: (totals.spend ?? 0) > 0 && Object.values(reported).some((v) => v === true) }
}
