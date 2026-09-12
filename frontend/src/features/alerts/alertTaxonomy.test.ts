import { describe, expect, it } from 'vitest'
import { ALERT_TYPES } from './api'
import { alertCategory, alertNextAction, ALERT_CATEGORY_ORDER } from './alertTaxonomy'

/**
 * ALERT-TAXONOMY-001 — severity says how loud, and nothing about what.
 *
 * An expiring token and a campaign spending with no results are both «warning». They go to different
 * people and lead to opposite actions, and the page offered no way to tell them apart but reading
 * each message. A reader scanning twenty alerts was sorting them in their head every time.
 *
 * The load-bearing case is the last one: every type the EVALUATOR can raise must be categorised
 * here, or the day somebody adds one this page quietly files it under «uncategorised» for ever.
 */
describe('what kind of problem an alert is', () => {
  it('files a budget alert under budget', () => {
    expect(alertCategory('budget_risk')).toBe('budget')
  })

  it('files the four performance types together', () => {
    for (const type of ['cpa_increase', 'cpl_increase', 'roas_drop', 'no_results']) {
      expect(alertCategory(type), type).toBe('performance')
    }
  })

  it('files the plumbing together, because one person fixes all of it', () => {
    for (const type of ['sync_failure', 'token_expiry', 'report_failed']) {
      expect(alertCategory(type), type).toBe('data')
    }
  })

  /** A type nobody has categorised is VISIBLE and uncategorised, never hidden. */
  it('gives an unknown type a place rather than dropping it', () => {
    expect(alertCategory('something_new')).toBe('other')
    expect(ALERT_CATEGORY_ORDER).toContain('other')
  })

  /**
   * Every type the product can raise has a home — the case that fails when somebody adds one.
   *
   * `ALERT_TYPES` is the picker's and the controller's own list, so this is checked against what the
   * product accepts rather than against a copy of it.
   */
  it('has a category for every type this product raises', () => {
    const uncategorised = ALERT_TYPES.filter((t) => alertCategory(t) === 'other')

    expect(uncategorised, `these alert types have no category:\n  ${uncategorised.join('\n  ')}`).toEqual([])
  })
})

describe('what to do about it', () => {
  it('says the same thing for a type every time, whatever the figures were', () => {
    expect(alertNextAction('token_expiry', 'en')).toMatch(/reconnect/i)
    expect(alertNextAction('token_expiry', 'ar')).toContain('أعد ربط')
  })

  /**
   * It instructs and does not decide.
   *
   * This product monitors and warns; it acts on no ad platform. «Reduce the budget» would read as a
   * decision somebody had taken, which is the same false implication the spend-limit chip avoids.
   */
  it('tells a person what to look at, not what the product did', () => {
    expect(alertNextAction('budget_risk', 'en')).toMatch(/review/i)
  })

  /** No invented filler: a type with nothing specific to say produces no line at all. */
  it('says nothing rather than «investigate this»', () => {
    expect(alertNextAction('something_new', 'en')).toBeNull()
  })

  /**
   * Every type that can actually BE raised has an action — and one type cannot.
   *
   * `AlertEvaluator::UNRAISED` names `sla_warning`: the picker offers it and the controller accepts
   * it, and no code anywhere raises it. The backend names it in a constant «so the coverage test can
   * assert it is KNOWN rather than merely missing», and this mirrors that exactly. Inventing advice
   * for an event that cannot occur would be filler dressed as coverage.
   */
  const UNRAISED = ['sla_warning']

  it('has an action for every type this product raises', () => {
    const silent = ALERT_TYPES.filter((t) => alertNextAction(t, 'en') === null && !UNRAISED.includes(t))

    expect(silent, `these alert types tell a reader nothing to do:\n  ${silent.join('\n  ')}`).toEqual([])
  })

  /** And the exception is asserted to still BE the exception, so the list cannot quietly grow. */
  it('has exactly one type that nothing raises', () => {
    expect(ALERT_TYPES.filter((t) => alertNextAction(t, 'en') === null)).toEqual(UNRAISED)
  })
})
