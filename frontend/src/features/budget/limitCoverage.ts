import type { SpendLimitReading, SpendLimitState } from './spendLimitsApi'

/**
 * BUDGET-CONNECTED-001 — which internal spend limits cover a campaign, and how it should read.
 *
 * ## Why this module exists
 *
 * A spend limit reached three places: its own page, the alert evaluator and the daily digest. It did
 * not reach the campaigns workspace — so an operator looking at a campaign that is at 94% of a limit
 * saw nothing about it on the screen where they would act. They had to already know the limit
 * existed and go and look. «Budgets connected across modules» is exactly this: a governance figure
 * nobody meets while working is governance in a drawer.
 *
 * ## Nothing here is a second arithmetic
 *
 * `SpendLimitGovernor::read()` already states `scope`, `scope_id`, `state` and `utilisation` for
 * every active limit, computed against the same money contract every other surface reads — including
 * its refusals, which are the part that matters most. This module answers ONE question the server
 * cannot answer per row without being asked per row: which of those limits cover this campaign.
 *
 * The hierarchy is the enum's own — a project limit contains its platforms, which contain their
 * accounts, which contain their campaigns — and two limits covering one campaign is a plan rather
 * than a contradiction: «10,000 across everything, and no more than 4,000 on TikTok».
 */
export type CoveredCampaign = {
  campaign_id: string | number
  /**
   * The platform this row is on, WHERE THE ROW KNOWS IT.
   *
   * A `UnifiedCampaign` deliberately does not: it is one campaign that may carry externals on
   * several platforms, which is the whole point of unifying them. So «does this platform limit cover
   * this campaign» is a question that row cannot answer, and guessing either way would be wrong in a
   * direction nobody could see. Absent here means the platform limits are reported as unmatched, the
   * same as the account rung — a metrics row that does carry a provider matches them normally.
   */
  provider?: string | null
}

export type Coverage = {
  /** The limits that certainly bind this campaign. */
  covering: SpendLimitReading[]
  /**
   * And the ones that MIGHT, which this grain cannot decide.
   *
   * Two rungs land here. A campaign row never carries the ad ACCOUNT it belongs to, so an
   * account-scoped limit cannot be matched at any grain this product offers. And a unified campaign
   * carries no PLATFORM, because it may span several — so a platform limit is undecidable from that
   * row, though a metrics row that does name a provider matches it normally.
   *
   * Dropping either silently would tell a reader their campaign is unconstrained when it may be the
   * one closest to breaching, so they are handed back and the surface says so.
   */
  unmatched: SpendLimitReading[]
}

export function coveringLimits(limits: SpendLimitReading[], campaign: CoveredCampaign): Coverage {
  const covering: SpendLimitReading[] = []
  const unmatched: SpendLimitReading[] = []

  for (const l of limits) {
    switch (l.scope) {
      case 'project':
        covering.push(l)
        break
      case 'platform':
        if (campaign.provider === undefined || campaign.provider === null) {
          unmatched.push(l)
        } else if (l.scope_id === campaign.provider) {
          covering.push(l)
        }
        break
      case 'campaign':
        /* String-compared: ids arrive numeric on some payloads and as strings on others. */
        if (l.scope_id !== null && String(l.scope_id) === String(campaign.campaign_id)) covering.push(l)
        break
      case 'account':
        unmatched.push(l)
        break
    }
  }

  return { covering, unmatched }
}

/**
 * The order of seriousness, and the reason `unknown` sits where it does.
 *
 * A limit whose spend cannot be compared — two currencies and no rate — is NOT evidence that the
 * campaign is fine, so it outranks `ok`: showing it as fine would be reporting safety the product
 * cannot see, which is the failure the whole governance feature exists to prevent. It is also not
 * more urgent than a breach somebody can act on today, so it sits below `over`.
 */
const SERIOUSNESS: Record<SpendLimitState, number> = { ok: 0, unknown: 1, approaching: 2, over: 3 }

/** The worst state among the limits covering a campaign, or null when none do. */
export function worstLimitState(limits: SpendLimitReading[]): SpendLimitState | null {
  if (limits.length === 0) return null

  return limits.reduce<SpendLimitState>(
    (worst, l) => (SERIOUSNESS[l.state] > SERIOUSNESS[worst] ? l.state : worst),
    'ok',
  )
}
