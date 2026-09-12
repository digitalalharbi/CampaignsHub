import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { SpendLimitChip } from './SpendLimitChip'
import { renderWithProviders } from '@/test/utils'
import type { SpendLimitReading } from './spendLimitsApi'

/**
 * BUDGET-CONNECTED-001 — the chip says something only when there is something to say.
 *
 * A badge on every card reading «within its limit» is noise, and noise teaches a reader to stop
 * seeing the badges — so the one that matters is the one they then miss. What must never happen is
 * the opposite: a campaign that is over a limit, or under one nobody can measure, looking exactly
 * like a campaign with no limit at all.
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

const NOTE = 'CampaignsHub watches spend against this limit and warns. It does not stop delivery on any ad platform.'

const render = (limits: SpendLimitReading[]) => renderWithProviders(
  <SpendLimitChip campaign={{ campaign_id: 'c-1' }} limits={limits} enforcementNote={NOTE} locale="en" />,
  { locale: 'en' },
)

describe('the spend-limit chip on a campaign', () => {
  it('draws nothing while every limit is fine', () => {
    render([limit()])

    expect(screen.queryByTestId('spend-limit-chip')).toBeNull()
    expect(screen.queryByTestId('spend-limit-unmatched')).toBeNull()
  })

  it('warns when a limit is being approached, with how far along it is', () => {
    render([limit({ state: 'approaching', utilisation: 0.87 })])

    const chip = screen.getByTestId('spend-limit-chip')
    expect(chip).toHaveAttribute('data-state', 'approaching')
    expect(chip).toHaveTextContent('87%')
  })

  it('says so when a limit has been passed', () => {
    render([limit({ state: 'over', utilisation: 1.12 })])

    expect(screen.getByTestId('spend-limit-chip')).toHaveAttribute('data-state', 'over')
  })

  /**
   * A limit that cannot be measured is SHOWN, in its own muted tone.
   *
   * Two currencies and no rate means the product cannot see whether this campaign is safe. Hiding
   * that reports safety it does not have — the exact failure governance exists to prevent, arriving
   * through the feature meant to prevent it.
   */
  it('shows a limit whose spend cannot be compared, rather than treating it as fine', () => {
    render([limit({ state: 'unknown', utilisation: null })])

    const chip = screen.getByTestId('spend-limit-chip')
    expect(chip).toHaveAttribute('data-state', 'unknown')
    expect(chip, 'an unmeasurable limit answered the question it had just refused').not.toHaveTextContent('%')
  })

  /** The worst of what covers it leads — a breach is not softened by a limit that is fine. */
  it('reports the most serious state among the limits covering it', () => {
    render([limit({ id: 'a', state: 'ok' }), limit({ id: 'b', state: 'over', utilisation: 1.3 })])

    expect(screen.getByTestId('spend-limit-chip')).toHaveAttribute('data-state', 'over')
  })

  /**
   * The enforcement sentence travels with it, and is the server's own.
   *
   * «Over its spend limit» on a campaign that is still serving is precisely where somebody assumes
   * something was paused for them. CampaignsHub watches and warns; it stops nothing.
   */
  it('carries what a limit actually does', () => {
    render([limit({ state: 'over' })])

    expect(screen.getByTestId('spend-limit-chip')).toHaveAttribute('title', NOTE)
  })

  /** A limit this grain cannot place is named, not dropped — «unconstrained» would be false. */
  it('says an account-level limit exists even when everything matchable is fine', () => {
    render([limit({ id: 'acc', scope: 'account', scope_id: 'act-1', state: 'over' })])

    expect(screen.queryByTestId('spend-limit-chip')).toBeNull()
    expect(screen.getByTestId('spend-limit-unmatched')).toBeVisible()
  })
})
