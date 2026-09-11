import { campaignRelevance, type RelevanceRow } from './campaignRelevance'

/**
 * CAMPAIGNS-OVERVIEW-FIRST-001 — the order a portfolio is READ in, which is not the order it was created in.
 *
 * «Do not treat all campaigns equally in the first view. A campaign the user can act on now must
 * rank above historical noise.» The owner's order, exactly:
 *
 *   1. needs attention   — something is wrong and a person can fix it today
 *   2. active and spending
 *   3. active but weak   — running, delivering, and not earning its money
 *   4. paused recently   — still in living memory, still restartable
 *   5. ended / archived  — history
 *
 * ## Why this is its own rule and not a sort on one column
 *
 * The existing `campaignRelevance` answers «is this thing running» from status and last activity.
 * That is one INPUT here. «Needs attention» is a different question — it is about whether the reader
 * has something to do — and «active but weak» is a third, about efficiency rather than delivery. A
 * single `ORDER BY status, spend DESC` cannot express any of it, and the page used to do exactly
 * that, which is why an archived campaign from March could outrank one overspending this morning.
 *
 * ## Ties
 *
 * Within a band, more spend first: the money already committed is what makes one of two equally
 * troubled campaigns the more urgent. Then by name, so the order is stable across renders rather
 * than left to the array's arrival order — a list that reshuffles on refresh cannot be scanned.
 */
export type CampaignBand = 'attention' | 'spending' | 'weak' | 'paused' | 'ended'

export const CAMPAIGN_BAND_ORDER: CampaignBand[] = ['attention', 'spending', 'weak', 'paused', 'ended']

export interface PriorityRow extends RelevanceRow {
  name?: string | null
  /** Whether this campaign raised something a person can act on — alerts, pacing, a broken link. */
  needs_attention?: boolean | null
  /**
   * The campaign's own efficiency verdict where the server has one.
   *
   * `null` is NOT «fine»: a campaign with no comparable figures has not been judged, and calling it
   * healthy would be the coalesced-zero mistake in another costume. Only an explicit `false` bands a
   * campaign as weak.
   */
  efficient?: boolean | null
}

/** How recently a paused campaign still counts as «paused recently» rather than as history. */
export const PAUSED_RECENTLY_DAYS = 30

/**
 * Which band this campaign belongs to.
 *
 * Read top-down, and the order of the arms IS the rule: a campaign that needs attention is in that
 * band whether or not it is also spending, because that is the one a reader must not have to find.
 */
export function campaignBand(row: PriorityRow, windowEnd: string): CampaignBand {
  if (row.needs_attention === true) {
    return 'attention'
  }

  const relevance = campaignRelevance(row, windowEnd)

  if (relevance === 'serving') {
    /* Running and delivering, but not earning it — an explicit verdict, never an absent one. */
    return row.efficient === false ? 'weak' : 'spending'
  }

  if (relevance === 'idle') {
    return 'paused'
  }

  const last = row.last_active_on ?? null
  if (last === null) {
    return 'ended'
  }

  const days = Math.floor((Date.parse(windowEnd) - Date.parse(last)) / 86_400_000)

  return days <= PAUSED_RECENTLY_DAYS ? 'paused' : 'ended'
}

/** The portfolio in reading order: what to act on first, then what is merely still running. */
export function byPriority<T extends PriorityRow>(rows: T[], windowEnd: string): T[] {
  return [...rows].sort((a, b) => {
    const band = CAMPAIGN_BAND_ORDER.indexOf(campaignBand(a, windowEnd)) - CAMPAIGN_BAND_ORDER.indexOf(campaignBand(b, windowEnd))
    if (band !== 0) return band

    const spend = (b.spend ?? 0) - (a.spend ?? 0)
    if (spend !== 0) return spend

    return String(a.name ?? '').localeCompare(String(b.name ?? ''))
  })
}

/** How many campaigns sit in each band — the portfolio's shape, for the classification strip. */
export function bandCounts(rows: PriorityRow[], windowEnd: string): Record<CampaignBand, number> {
  const counts: Record<CampaignBand, number> = { attention: 0, spending: 0, weak: 0, paused: 0, ended: 0 }

  for (const row of rows) {
    counts[campaignBand(row, windowEnd)] += 1
  }

  return counts
}
