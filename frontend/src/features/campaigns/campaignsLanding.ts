import type { BudgetRow } from '@/features/analytics/api'

import { campaignState } from './campaignState'
import { campaignCostKey, campaignResultKey } from './campaignHeadline'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — what the workspace answers before the reader scrolls.
 *
 * The requirement asks the landing to say what needs attention, where the opportunity is, and which
 * budget is inefficient. The temptation is a scoreboard — three big numbers, always populated,
 * always confident. That is the «arbitrary opaque health score» this requirement forbids, split into
 * three.
 *
 * So each answer is a COUNT of rows that already carry their own evidence, and each one can be
 * absent. «Nothing needs attention» and «we could not examine anything» are different answers and
 * neither is a zero with a label.
 *
 * Nothing here reasons. Pacing comes from the backend's `budgetPacing`, which alone knows whether a
 * spend and a budget are even denominated in the same currency. A second opinion on it would
 * eventually disagree with the row a click away.
 *
 * ## NEEDS-ATTENTION-ONE-DEFINITION-001 — and attention is not decided here at all
 *
 * It used to be. This function asked `campaignState()` for a metrics weakness while the KPI card,
 * the band chip and the attention LIST beside it all counted `attentionFlags()` — operational
 * evidence a reader can open: unlinked, over budget, no results, stale. So one screen said «needs
 * attention» twice, from two engines, and the two numbers disagreed the moment either one changed.
 * A reader cannot tell which of them is wrong; they can only learn to trust neither.
 *
 * The flags win the phrase, because theirs is the count that can be opened and read back as reasons.
 * This function is now TOLD which campaigns were flagged, and only sorts the rest into «examined and
 * fine» and «nothing could be said» — two answers it still owns, and which are still not the same
 * answer.
 */

export interface LandingAnswer {
  /** Campaigns `attentionFlags()` raised something on — the same set the attention list shows. */
  needsAttention: number
  /** Campaigns examined and healthy — the reader is entitled to this as its own answer. */
  healthy: number
  /**
   * Campaigns nothing could be said about: no spend, or nothing reported. Counted separately because
   * folding them into `healthy` publishes an absence of evidence as evidence of health.
   */
  unexamined: number
  /**
   * Budgets running hot enough to exhaust early — from the backend's own pacing, and only where a
   * pace could be computed at all. Null when no row could be paced, which is a different fact from
   * «no budget is overspending».
   */
  overpacing: number | null
  /**
   * CAMPAIGN-INTELLIGENCE-HUB — where the opportunity is: campaigns whose OWN cost per result is clearly better than their peers'.
   * A peer is a campaign in the same {@link comparisonGroup} — same objective, same outcome the platform optimised for (the event AND
   * the path: a native form's lead is not a website lead), spend wholly in the reporting currency, one window — with at least
   * {@link LANDING_COMPARISON}.minResults reported results. Null when no group had {@link LANDING_COMPARISON}.minPeers such peers —
   * «nothing to compare» is not «no opportunity».
   */
  opportunity: number | null
  /**
   * Which budget is inefficient, inside the same groups: a cost per result at least twice the peers' median, or a result the platform
   * REPORTED as zero against spend that would have bought {@link LANDING_COMPARISON}.minResults at the peers' usual price — a zero on a
   * small spend is a small sample, not waste. Null when no group could be compared. An unreported result is never read as zero.
   */
  inefficient: number | null
  /**
   * The evidence behind the two counts, one entry per group that could be compared — so the reader sees WHY a campaign was
   * flagged (its cost against the group's median, or a reported zero against the spend floor), how large the sample was and which
   * outcome it was compared on. The surface adds the period and currency, which this module does not hold.
   */
  comparisons: LandingComparison[]
}

/** One compared group and what it flagged — the explanation of a count, never a verdict of waste. */
export interface LandingComparison {
  objective: string | null
  outcome: string
  outcomeLabel: { ar?: string; en?: string } | null
  /** Campaigns in the group that cleared the results floor — the sample the median is read from. */
  peers: number
  /** The peers' median cost per result, in the reporting currency. */
  usualCost: number
  /** At or below this cost per result reads as an opportunity. */
  opportunityAtMost: number
  /** At or above this cost per result reads as costlier than the peers. */
  costlierAtLeast: number
  /** A reported zero is flagged only once spend reaches this. */
  zeroSpendFloor: number
  flagged: Array<{ id: string; name: string | null; kind: 'opportunity' | 'costlier' | 'zero'; cost: number | null; spend: number; results: number }>
}

/**
 * The comparison's floors and ratios, exported so the tests derive their expectations from them rather than retyping them.
 *
 * - minPeers: campaigns needed in one group before a cost is called better or worse than «usual».
 * - minResults: reported results a campaign needs before its cost per result is a measurement rather than noise — and the spend a
 *   reported zero must reach (at the peers' usual price) before it is called inefficient.
 * - opportunityRatio: «clearly better» — at most three quarters of the peers' median.
 * - inefficientRatio: «inefficient» — at least twice the peers' median.
 */
export const LANDING_COMPARISON = { minPeers: 3, minResults: 10, opportunityRatio: 0.75, inefficientRatio: 2 } as const

/**
 * Which campaigns may be compared with which — null when this one may not be compared at all.
 *
 * - `outcome` is the server's `CampaignOutcome`: the event and the path in one value (native form, website lead, messaging…), and
 *   `comparableWith()` there refuses `unknown` against everything, including another unknown. Same here.
 * - Any spend row withheld from the reporting currency leaves the campaign's spend partial, so its cost per result is not in the
 *   currency its peers' are.
 * - The window is the same by construction: every row comes from the one `byCampaign` request for the page's range.
 */
const comparisonGroup = (objective: string | null, row: Record<string, unknown> | undefined): string | null => {
  if (!row || campaignCostKey(objective) === undefined) return null
  const outcome = typeof row.outcome === 'string' ? row.outcome : 'unknown'
  if (outcome === 'unknown') return null
  if (Number(row.spend_withheld_rows ?? 0) > 0) return null
  return `${objective ?? ''}|${outcome}`
}

const median = (values: number[]): number => {
  const sorted = [...values].sort((a, b) => a - b)
  const mid = Math.floor(sorted.length / 2)
  return sorted.length % 2 === 0 ? (sorted[mid - 1] + sorted[mid]) / 2 : sorted[mid]
}

/** A figure the row REPORTED (never a coalesced zero), as a number; null otherwise. */
const reportedNumber = (row: Record<string, unknown> | undefined, key: string | undefined): number | null => {
  if (!row || !key) return null
  const reported = (row.reported as Record<string, boolean> | undefined)?.[key]
  const value = row[key]
  return reported === true && typeof value === 'number' && Number.isFinite(value) ? value : null
}

/** The objective's reported result and the row's spend, or nothing when either is missing. */
const resultAndSpend = (objective: string | null, row: Record<string, unknown> | undefined): { result: number; spend: number } | null => {
  const result = reportedNumber(row, campaignResultKey(objective))
  const spend = typeof row?.spend === 'number' && Number.isFinite(row.spend) ? row.spend : null
  if (result === null || spend === null || spend <= 0) return null
  return { result, spend }
}

/** A campaign pacing above this much of its plan for the elapsed period is running hot. */
const HOT_PACE = 1.2

export function landingAnswer(
  campaigns: { id: string; objective: string | null; name?: string | null }[],
  metricsByCampaign: Map<string, Record<string, unknown>>,
  budgets: BudgetRow[] | undefined,
  /*
   * The campaigns `attentionFlags()` raised something on — REQUIRED, never defaulted.
   *
   * A default would let a future caller quietly get the old second opinion back, which is the exact
   * shape of the defect this parameter exists to close.
   */
  flagged: ReadonlySet<string>,
): LandingAnswer {
  let needsAttention = 0
  let healthy = 0
  let unexamined = 0

  for (const c of campaigns) {
    // Attention is decided elsewhere, by the flags the list beside this strip shows.
    if (flagged.has(c.id)) {
      needsAttention++
      continue
    }

    const state = campaignState(c.objective, metricsByCampaign.get(c.id))

    // Unflagged and unjudgeable. Not healthy — nothing was examined, and the two are different news.
    if (!state.judged) {
      unexamined++
      continue
    }

    healthy++
  }

  /*
   * Only rows the backend could actually pace. `pacing_basis` names the reasons it could not — a
   * currency mismatch, no budget, a partial or mixed-currency spend — and counting those as «not
   * overpacing» would answer «is any budget running hot» with a confident no derived from rows
   * nobody could measure.
   */
  const paceable = (budgets ?? []).filter((b) => b.pacing_basis === 'comparable' && b.pace !== null)

  /*
   * Opportunity and inefficiency, inside each comparison group only: a lead's cost is never compared with an order's, a native
   * form's with a website lead's, or a partial-currency spend with a whole one.
   */
  const { minPeers, minResults, opportunityRatio, inefficientRatio } = LANDING_COMPARISON
  type Peer = { id: string; name: string | null; cost: number; spend: number; results: number }
  type Zero = { id: string; name: string | null; spend: number }
  const groups = new Map<string, { objective: string | null; outcome: string; label: LandingComparison['outcomeLabel']; peers: Peer[]; zeros: Zero[] }>()
  for (const c of campaigns) {
    const row = metricsByCampaign.get(c.id)
    const group = comparisonGroup(c.objective, row)
    const figures = resultAndSpend(c.objective, row)
    if (group === null || figures === null || row === undefined) continue
    const entry = groups.get(group) ?? {
      objective: c.objective,
      outcome: String(row.outcome),
      label: (row.outcome_label as LandingComparison['outcomeLabel']) ?? null,
      peers: [],
      zeros: [],
    }
    const name = c.name ?? null
    if (figures.result >= minResults) entry.peers.push({ id: c.id, name, cost: figures.spend / figures.result, spend: figures.spend, results: figures.result })
    else if (figures.result === 0) entry.zeros.push({ id: c.id, name, spend: figures.spend })
    // Between zero and the floor: too few results to say anything either way.
    groups.set(group, entry)
  }
  let opportunity = 0
  const inefficientIds = new Set<string>()
  const comparisons: LandingComparison[] = []
  for (const g of groups.values()) {
    if (g.peers.length < minPeers) continue
    const usual = median(g.peers.map((p) => p.cost))
    const comparison: LandingComparison = {
      objective: g.objective,
      outcome: g.outcome,
      outcomeLabel: g.label,
      peers: g.peers.length,
      usualCost: usual,
      opportunityAtMost: usual * opportunityRatio,
      costlierAtLeast: usual * inefficientRatio,
      zeroSpendFloor: usual * minResults,
      flagged: [],
    }
    for (const p of g.peers) {
      if (p.cost <= comparison.opportunityAtMost) {
        opportunity++
        comparison.flagged.push({ id: p.id, name: p.name, kind: 'opportunity', cost: p.cost, spend: p.spend, results: p.results })
      }
      if (p.cost >= comparison.costlierAtLeast) {
        inefficientIds.add(p.id)
        comparison.flagged.push({ id: p.id, name: p.name, kind: 'costlier', cost: p.cost, spend: p.spend, results: p.results })
      }
    }
    // A reported zero is flagged only once the spend would have bought the floor's worth of results at the usual price.
    for (const z of g.zeros) {
      if (z.spend < comparison.zeroSpendFloor) continue
      inefficientIds.add(z.id)
      comparison.flagged.push({ id: z.id, name: z.name, kind: 'zero', cost: null, spend: z.spend, results: 0 })
    }
    comparisons.push(comparison)
  }
  const compared = comparisons.length > 0

  return {
    needsAttention,
    healthy,
    unexamined,
    overpacing: paceable.length === 0 ? null : paceable.filter((b) => (b.pace ?? 0) > HOT_PACE).length,
    opportunity: compared ? opportunity : null,
    inefficient: compared ? inefficientIds.size : null,
    comparisons,
  }
}
