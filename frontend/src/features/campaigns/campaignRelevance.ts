/**
 * ENTITY-RELEVANCE-ORDERING-001 — the operational reading of a campaign, in one place.
 *
 * The backend orders `byCampaign()` spend-first and keeps it that way on purpose: the same breakdown
 * feeds reports, live report links and the daily digest, where «the top campaigns» means the ones
 * that spent the most, and re-ranking them by recency would change what those documents say.
 *
 * An operator opening the campaigns workspace is asking a different question — what is running, what
 * needs looking at, what has finished — and this is the single answer to it. Every operational
 * listing reads from here rather than growing its own rule, because two screens that disagree about
 * which campaigns are live is worse than either order alone.
 *
 * Nothing here invents data. `status` and `last_active_on` are facts the backend states; this only
 * decides what an operator does with them.
 */

/** The three states, in the order an operator reads them. */
export type CampaignRelevance = 'serving' | 'idle' | 'stopped'

export const CAMPAIGN_RELEVANCE_ORDER: CampaignRelevance[] = ['serving', 'idle', 'stopped']

/** The shape this rule needs — a subset of `CampaignRow`, so any row already carrying it fits. */
export interface RelevanceRow {
  campaign_id: string
  status: string | null
  last_active_on: string | null
  spend: number | null
}

/**
 * How far behind the window's end a campaign's last figure may be and still count as serving.
 *
 * Reporting lags: a platform's figures for yesterday routinely arrive today, and some arrive the day
 * after. A campaign whose most recent figure is two days old is running, and calling it idle would
 * send an operator to fix something that is working. Three days is the point past which silence stops
 * being lag and starts being a fact about the campaign.
 */
const SERVING_WITHIN_DAYS = 3

const DAY = 86_400_000

/**
 * Statuses that mean the campaign is FINISHED WITH — halted, done, filed away.
 *
 * `draft` and `pending` are deliberately absent, and that absence was bought with a real defect: a
 * campaign is created as `draft`, so filing draft under «stopped» meant the campaign an operator had
 * just created disappeared from the list they created it in. A draft has not stopped — it has not
 * started, which is work still in hand and belongs beside the running ones. `campaigns.spec.ts` and
 * `campaigns-linking.spec.ts` both caught it.
 *
 * `unknown` is absent for a different reason — see below.
 */
const NOT_RUNNING = new Set(['paused', 'completed', 'archived'])

export function campaignRelevance(row: RelevanceRow, windowEnd: string): CampaignRelevance {
  /*
   * A stopped campaign is stopped however much it spent. This is the ordering defect stated as a
   * rule: a finished campaign that outspent every running one used to lead the operational list, so
   * the first thing an operator saw was a campaign they could do nothing about.
   */
  if (row.status !== null && NOT_RUNNING.has(row.status)) return 'stopped'

  /*
   * A campaign that has not started yet is not serving and is not finished. `idle` is the bucket for
   * «switched on and producing nothing», and a draft is the same shape of thing: the operator's own
   * unfinished work, which they should see.
   */

  /*
   * `unknown` and null are NOT read as stopped. The platform did not tell us the state; the
   * campaign's own activity is the only evidence there is, and treating missing information as a
   * claim that the campaign ended would be inventing the answer.
   */
  if (row.last_active_on === null) return 'idle'

  const end = Date.parse(`${windowEnd}T00:00:00Z`)
  const last = Date.parse(`${row.last_active_on}T00:00:00Z`)
  if (Number.isNaN(end) || Number.isNaN(last)) return 'idle'

  return end - last <= SERVING_WITHIN_DAYS * DAY ? 'serving' : 'idle'
}

/**
 * Relevance first, then spend, then a key that cannot move.
 *
 * The id tiebreak matters for the same reason it does in the aggregator: a listing full of campaigns
 * that spent nothing is made entirely of ties, and rows that swap between two identical reads tell a
 * reader something changed when nothing did.
 */
export function orderByRelevance<T extends RelevanceRow>(rows: T[], windowEnd: string): T[] {
  const rank = (r: T) => CAMPAIGN_RELEVANCE_ORDER.indexOf(campaignRelevance(r, windowEnd))

  return [...rows].sort((a, b) => {
    const byState = rank(a) - rank(b)
    if (byState !== 0) return byState

    const bySpend = (b.spend ?? 0) - (a.spend ?? 0)
    if (bySpend !== 0) return bySpend

    return a.campaign_id < b.campaign_id ? -1 : a.campaign_id > b.campaign_id ? 1 : 0
  })
}
