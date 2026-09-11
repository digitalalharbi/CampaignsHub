/**
 * CAMPAIGNS-OVERVIEW-FIRST-001 — «which campaigns are deteriorating, and which are improving».
 *
 * The portfolio bands answer «what state is this in». They cannot answer «what CHANGED», and a
 * reader scanning for a problem is usually looking for movement rather than for a level: a campaign
 * that has been spending steadily for a month is not the story, and one that doubled overnight is.
 *
 * ## What is measured, and what is not
 *
 * SPEND movement, because that is the per-campaign comparison the server actually computes:
 * `byCampaign()` takes an optional previous window and returns `previous_spend` and `spend_change`
 * beside every row. There is no per-campaign result or efficiency comparison on this payload, and
 * deriving one from a single window would be a trend invented out of an absence — the mistake this
 * product has already fixed twice, once as a coalesced zero and once as an averaged ratio.
 *
 * So this block says exactly what it measures: money moving, not performance improving. Naming it
 * «best» and «worst» would be the claim the figures cannot carry.
 *
 * ## The nulls are the point
 *
 * `spend_change` is null when there is NO baseline — the campaign did not exist in the comparison
 * window, or the caller asked for no comparison at all — and additionally when the baseline is zero,
 * because every rise from nothing is infinite. A campaign with no baseline is EXCLUDED from both
 * lists rather than sorted as zero: «no trend» ranked among real trends is the same lie as «no data»
 * rendered as a zero.
 */
export interface MoverRow {
  id: string
  name: string | null
  spend: number | null
  previous_spend: number | null
  spend_change: number | null
}

export interface Movers<T> {
  /** Largest rises first. */
  up: T[]
  /** Largest falls first. */
  down: T[]
  /** How many campaigns could not be ranked because they have no baseline to move against. */
  withoutBaseline: number
}

/**
 * The campaigns that moved, split by direction.
 *
 * `limit` bounds each list rather than the pair: a portfolio where everything rose should show five
 * risers and an empty fall list, not five rows shared between them.
 */
export function movers<T extends MoverRow>(rows: T[], limit = 5): Movers<T> {
  const comparable = rows.filter((r) => r.spend_change !== null && r.previous_spend !== null)

  const byMagnitude = (dir: 1 | -1) => comparable
    .filter((r) => dir * (r.spend_change as number) > 0)
    .sort((a, b) => {
      const delta = dir * ((b.spend_change as number) - (a.spend_change as number))
      if (delta !== 0) return delta

      /* A tie falls back to the money at stake, then to the name, so the order cannot reshuffle. */
      const spend = (b.spend ?? 0) - (a.spend ?? 0)
      if (spend !== 0) return spend

      return String(a.name ?? '').localeCompare(String(b.name ?? ''))
    })
    .slice(0, limit)

  return {
    up: byMagnitude(1),
    down: byMagnitude(-1),
    withoutBaseline: rows.length - comparable.length,
  }
}
