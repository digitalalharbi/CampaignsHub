import type { BudgetRow } from '@/features/analytics/api'

import { campaignState } from './campaignState'

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
 * Nothing here reasons. Attention comes from the shared diagnostic engine via `campaignState`;
 * pacing comes from the backend's `budgetPacing`, which alone knows whether a spend and a budget are
 * even denominated in the same currency. A second opinion on either would eventually disagree with
 * the row a click away.
 */

export interface LandingAnswer {
  /** Campaigns the engine examined and found a weakness in. */
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
}

/** A campaign pacing above this much of its plan for the elapsed period is running hot. */
const HOT_PACE = 1.2

export function landingAnswer(
  campaigns: { id: string; objective: string | null }[],
  metricsByCampaign: Map<string, Record<string, unknown>>,
  budgets: BudgetRow[] | undefined,
): LandingAnswer {
  let needsAttention = 0
  let healthy = 0
  let unexamined = 0

  for (const c of campaigns) {
    const state = campaignState(c.objective, metricsByCampaign.get(c.id))

    if (!state.judged) {
      unexamined++
      continue
    }

    if (state.finding !== null) {
      needsAttention++
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

  return {
    needsAttention,
    healthy,
    unexamined,
    overpacing: paceable.length === 0 ? null : paceable.filter((b) => (b.pace ?? 0) > HOT_PACE).length,
  }
}
