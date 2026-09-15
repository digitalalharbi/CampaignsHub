import type { CampaignRow } from './api'

/**
 * MONEY-TRUTH — choosing a best and a worst by ROAS, from the rows that HAVE one.
 *
 * Both panels on the campaigns tab were picking wrongly, in two different ways.
 *
 * «Best campaign (ROAS)» was `rows[0]`. Campaign rows arrive spend-ordered — `orderCampaignRows()`
 * is `orderBySpendThen()` — so the panel named the biggest spender and printed its ROAS beside it.
 * The heading made a claim about efficiency over a ranking by size.
 *
 * «Needs a look (lowest ROAS)» sorted on `(a.roas ?? 0) - (b.roas ?? 0)`. A campaign whose platform
 * never reported ROAS arrives without one, and coalescing that to zero sank it to the bottom — so
 * the panel could name a campaign nobody has a return figure for over one that genuinely returned
 * little. That is the unreported-as-zero error committed inside a ranking, where it is invisible:
 * the figure beside the name reads «—», and the name is still wrong.
 *
 * A REPORTED zero is a different thing and is kept: a campaign that returned nothing on real spend
 * is exactly what this panel exists to surface.
 */
const hasReportedRoas = (row: CampaignRow): boolean => {
  const reported = (row as unknown as { reported?: Record<string, boolean> }).reported

  /*
   * ROAS is DERIVED, so the `reported` map never carries it — and asking the map for `roas` is the
   * mistake this comment exists to stop.
   *
   * The map lists the columns the aggregator SUMS: spend, revenue, conversions, impressions and the
   * rest. Gating on `reported.roas === true` therefore excluded every campaign, and both panels went
   * silent on an estate where two campaigns had genuine returns of 12.08× and 14.41×. Caught by
   * opening the page after the change, which is the only reason it is not in this branch.
   *
   * A derived figure is reportable when its INPUTS are. Revenue is the input the platform either
   * sent or did not, and it IS in the map; spend is required separately because a ratio over no
   * spend is not a return, it is a division by nothing.
   */
  const revenueReported = reported === undefined ? row.revenue !== null && row.revenue !== undefined : reported.revenue === true

  return revenueReported && typeof row.roas === 'number'
}

const roasOf = (row: CampaignRow): number => Number(row.roas ?? 0)

/** The highest ROAS among campaigns that reported one, or null when none did. */
export function bestByRoas(rows: CampaignRow[]): CampaignRow | null {
  const eligible = rows.filter(hasReportedRoas)

  return eligible.length === 0
    ? null
    : [...eligible].sort((a, b) => roasOf(b) - roasOf(a))[0]
}

/**
 * The lowest ROAS among campaigns that spent real money and reported a return.
 *
 * Spend is required because a campaign that spent nothing cannot have performed badly — it has not
 * performed at all, and naming it would send an operator to a campaign with nothing to fix.
 */
export function worstByRoas(rows: CampaignRow[]): CampaignRow | null {
  const eligible = rows.filter((r) => Number(r.spend ?? 0) > 0 && hasReportedRoas(r))

  return eligible.length === 0
    ? null
    : [...eligible].sort((a, b) => roasOf(a) - roasOf(b))[0]
}
