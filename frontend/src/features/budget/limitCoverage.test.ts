import { describe, expect, it } from 'vitest'
import { coveringLimits, worstLimitState, type CoveredCampaign } from './limitCoverage'
import type { SpendLimitReading } from './spendLimitsApi'

/**
 * BUDGET-CONNECTED-001 — a spend limit is visible where the money is being spent.
 *
 * ## The gap
 *
 * `SpendLimit` reaches three places: its own page, the alert evaluator and the daily digest. It does
 * not reach the campaigns workspace. So an operator reading a campaign that is at 94% of a limit
 * sees nothing about it on the screen where they would act — they have to already know the limit
 * exists and go and look. The requirement is that budgets are «connected across modules», and a
 * governance figure nobody meets while working is governance in a drawer.
 *
 * ## Why a matcher and not a new figure
 *
 * `SpendLimitGovernor::read()` already states `scope`, `scope_id`, `state` and `utilisation` for
 * every active limit, computed against the same money contract every other surface uses. Nothing
 * here recomputes any of it. The only question this module answers is which of those limits COVER a
 * given campaign — and the hierarchy that decides it is the enum's own: a project limit contains its
 * platforms, which contain their accounts, which contain their campaigns.
 *
 * ## The account rung, stated rather than dropped
 *
 * A campaign row carries its id and its provider. It does not carry the ad account, so an
 * account-scoped limit cannot be matched at this grain. Silently ignoring it would tell a reader
 * their campaign is unconstrained when it is not — so it is reported as unmatched, and the surface
 * can say «one limit could not be matched to this campaign» instead of implying there is none.
 */
const limit = (over: Partial<SpendLimitReading> = {}): SpendLimitReading => ({
  id: 'l1',
  scope: 'project',
  scope_id: null,
  enforcement: 'monitor_only',
  amount: 10_000,
  currency: 'SAR',
  period: { from: '2026-07-01', to: '2026-07-31', days: 31 },
  elapsed_days: 15,
  consumed: 5_000,
  consumed_currency: 'SAR',
  remaining: 5_000,
  utilisation: 0.5,
  pace: 1,
  projected_period_spend: 10_000,
  projected_exhaustion: { date: null, reason: 'too_early' },
  thresholds: [80],
  state: 'ok',
  basis: null,
  ...over,
}) as SpendLimitReading

const campaign: CoveredCampaign = { campaign_id: 'c-1', provider: 'meta' }

describe('which limits cover a campaign', () => {
  it('a project limit covers every campaign in the project', () => {
    expect(coveringLimits([limit()], campaign).covering.map((l) => l.id)).toEqual(['l1'])
  })

  it('a platform limit covers the campaigns on that platform and no others', () => {
    const meta = limit({ id: 'meta', scope: 'platform', scope_id: 'meta' })
    const snap = limit({ id: 'snap', scope: 'platform', scope_id: 'snapchat' })

    expect(coveringLimits([meta, snap], campaign).covering.map((l) => l.id)).toEqual(['meta'])
  })

  it('a campaign limit covers the campaign it names', () => {
    const mine = limit({ id: 'mine', scope: 'campaign', scope_id: 'c-1' })
    const other = limit({ id: 'other', scope: 'campaign', scope_id: 'c-2' })

    expect(coveringLimits([mine, other], campaign).covering.map((l) => l.id)).toEqual(['mine'])
  })

  /** Ids arrive as numbers on some payloads and strings on others; a match must not depend on which. */
  it('matches a campaign id whatever type it arrived as', () => {
    const numeric: CoveredCampaign = { campaign_id: 7, provider: 'meta' }
    const named = limit({ id: 'n', scope: 'campaign', scope_id: '7' })

    expect(coveringLimits([named], numeric).covering.map((l) => l.id)).toEqual(['n'])
  })

  /**
   * A unified campaign spans platforms, so a platform limit is undecidable from that row.
   *
   * It is the same honesty as the account rung and for a different reason: the row does not carry a
   * provider because the entity genuinely has more than one. Matching it either way would be a guess
   * nobody could see, and dropping it would say «unconstrained» about a campaign that is not.
   */
  it('reports a platform limit as unmatched when the row spans platforms', () => {
    const unified: CoveredCampaign = { campaign_id: 'c-1' }
    const platform = limit({ id: 'pl', scope: 'platform', scope_id: 'meta' })

    const read = coveringLimits([platform], unified)

    expect(read.covering).toEqual([])
    expect(read.unmatched.map((l) => l.id)).toEqual(['pl'])
  })

  /**
   * An account limit is REPORTED as unmatched, never dropped.
   *
   * The campaign row has no account on it. Treating that as «no limit applies» tells a reader their
   * campaign is unconstrained when it may be the one closest to breaching.
   */
  it('reports an account limit it cannot match rather than ignoring it', () => {
    const account = limit({ id: 'acc', scope: 'account', scope_id: 'act-1' })

    const read = coveringLimits([account], campaign)

    expect(read.covering).toEqual([])
    expect(read.unmatched.map((l) => l.id)).toEqual(['acc'])
  })

  /** Two limits may cover one campaign on purpose — «10,000 overall, 4,000 on TikTok» is one plan. */
  it('keeps every limit that covers, not the first', () => {
    const project = limit({ id: 'p' })
    const platform = limit({ id: 'pl', scope: 'platform', scope_id: 'meta' })

    expect(coveringLimits([project, platform], campaign).covering).toHaveLength(2)
  })
})

describe('the state a campaign should be shown', () => {
  /** The worst of what covers it: «over» outranks «approaching», which outranks «ok». */
  it('reports the most serious state among the limits that cover it', () => {
    const states = [limit({ id: 'a', state: 'ok' }), limit({ id: 'b', state: 'over' }), limit({ id: 'c', state: 'approaching' })]

    expect(worstLimitState(states)).toBe('over')
  })

  it('prefers approaching over ok', () => {
    expect(worstLimitState([limit({ state: 'ok' }), limit({ state: 'approaching' })])).toBe('approaching')
  })

  /**
   * «Unknown» outranks «ok» and not the two that mean something.
   *
   * A limit whose spend cannot be compared — two currencies, no rate — is not evidence that the
   * campaign is fine, and showing it as `ok` would be reporting safety the product cannot see. It is
   * also not more urgent than a breach somebody can act on.
   */
  it('treats an uncomparable limit as worse than fine and less urgent than a breach', () => {
    expect(worstLimitState([limit({ state: 'ok' }), limit({ state: 'unknown' })])).toBe('unknown')
    expect(worstLimitState([limit({ state: 'unknown' }), limit({ state: 'over' })])).toBe('over')
  })

  /** Nothing covering it is not a state — the caller shows no badge at all. */
  it('has no state when nothing covers the campaign', () => {
    expect(worstLimitState([])).toBeNull()
  })
})
