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
export const SERVING_WITHIN_DAYS = 3

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
/**
 * Exported so the SERVER's copy can be held equal to it — see `relevanceDefinition.test.ts`.
 *
 * A paged listing has to express this order in SQL, and a SQL order needs these values. Exporting
 * them is what keeps that from becoming a second definition.
 */
export const NOT_RUNNING_STATUSES = ['paused', 'completed', 'archived'] as const

const NOT_RUNNING = new Set<string>(NOT_RUNNING_STATUSES)

/**
 * The two facts the rule actually reads.
 *
 * Named separately from `RelevanceRow` because ad sets and ads carry exactly these and no
 * `campaign_id` — and «is this still running» is the same question at every rung. Splitting the
 * INPUT rather than copying the rule is the whole point: the ad table asking its own version of this
 * is how two surfaces come to disagree about which things are live.
 */
export interface RelevanceFacts {
  status: string | null
  last_active_on: string | null
}

export function campaignRelevance(row: RelevanceRow, windowEnd: string): CampaignRelevance {
  return relevanceOf(row, windowEnd)
}

/** The rule itself, over the two facts — usable at any rung of the hierarchy. */
export function relevanceOf(row: RelevanceFacts, windowEnd: string): CampaignRelevance {
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
 * The same ordering, for any rung that carries the two facts, a spend and a stable id.
 *
 * Ad sets and ads are ordered by the identical rule the campaigns workspace uses, because «serving
 * first, then by spend» is one product decision and not three. The id accessor is a parameter rather
 * than a fixed field name only because the rungs name their key differently.
 */
export function orderByRelevanceWith<T extends RelevanceFacts & { spend: number | null }>(
  rows: T[],
  windowEnd: string,
  idOf: (row: T) => string,
): T[] {
  const rank = (r: T) => CAMPAIGN_RELEVANCE_ORDER.indexOf(relevanceOf(r, windowEnd))

  return [...rows].sort((a, b) => {
    const byState = rank(a) - rank(b)
    if (byState !== 0) return byState

    const bySpend = (b.spend ?? 0) - (a.spend ?? 0)
    if (bySpend !== 0) return bySpend

    const ia = idOf(a)
    const ib = idOf(b)

    return ia < ib ? -1 : ia > ib ? 1 : 0
  })
}

/**
 * Relevance first, then spend, then a key that cannot move.
 *
 * The id tiebreak matters for the same reason it does in the aggregator: a listing full of campaigns
 * that spent nothing is made entirely of ties, and rows that swap between two identical reads tell a
 * reader something changed when nothing did.
 */
export function orderByRelevance<T extends RelevanceRow>(rows: T[], windowEnd: string): T[] {
  return orderByRelevanceWith(rows, windowEnd, (row) => row.campaign_id)
}

/**
 * REPORT-SCOPE-SELECTION-001 — reportability, which is a DIFFERENT question from relevance.
 *
 * ## Why this is not `campaignRelevance` with another name
 *
 * The rule above answers «what can an operator act on now», and its first clause is that a stopped
 * campaign is stopped however much it spent — deliberately, because a finished campaign that
 * outspent every running one used to lead the operational list and the first thing a person saw was
 * something they could do nothing about.
 *
 * A report builder asks the opposite question: «what contributed to the period I am reporting on».
 * A campaign completed in August was quite possibly the account's largest spender in the July report
 * being built, and the operational rule files it under «stopped» — burying it beneath campaigns that
 * are running today and contributed nothing to that month. The matrix states the distinction
 * outright: «Reportability = campaign lifecycle + selected period + canonical status — NOT a
 * simplistic `status === active` frontend filter».
 *
 * So two rules, side by side in one file, because the temptation to reuse the wrong one is exactly
 * what this comment exists to interrupt.
 *
 * ## What decides it
 *
 * `last_active_on` from the server, which is already bounded to the report's window and already
 * filtered to days with a positive figure. Nothing here re-derives either: a date means it ran, a
 * null with a period asked means it did not, and a null with no period asked means nobody asked.
 */
export type Reportability = 'ran' | 'silent' | 'unknown'

export function reportability(
  row: { last_active_on: string | null },
  period: { from?: string | null; to?: string | null },
): Reportability {
  if (!period.from || !period.to) return 'unknown'

  return row.last_active_on === null ? 'silent' : 'ran'
}

/**
 * The campaigns a report builder should read first, in the order it should read them.
 *
 * Ran first, most recently active at the top — «what was this month» in the order it happened. The
 * rest keep the list's own order, which is by name: with nothing to say about them, inventing a
 * ranking would be a claim.
 *
 * Membership never changes. The heading decides order and emphasis, and a campaign that did not run
 * is still selectable — an operator may have a reason this rule does not know.
 */
export function orderByReportability<T extends { last_active_on: string | null }>(
  rows: T[],
  period: { from?: string | null; to?: string | null },
): T[] {
  if (!period.from || !period.to) return rows

  const ran = rows.filter((r) => r.last_active_on !== null)
    .sort((a, b) => String(b.last_active_on).localeCompare(String(a.last_active_on)))

  return [...ran, ...rows.filter((r) => r.last_active_on === null)]
}
