import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen, within } from '@testing-library/react'

import { LANDING_COMPARISON, landingAnswer } from './campaignsLanding'
import { LandingComparisonChip } from './LandingComparisonChip'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the landing's opportunity and «costlier» counts are EXPLANATIONS (Owner, 2026-10-29).
 *
 * Each opens onto why a campaign was flagged, how large the sample was, the reference figure it was held to, and the period,
 * currency and outcome compared — and none of it calls spend waste. Every expectation is derived from the fixture and from
 * `LANDING_COMPARISON`, never typed as a literal.
 */
const { minPeers, minResults } = LANDING_COMPARISON
const OUTCOME = 'native_lead_form'
const LABEL = { ar: 'نموذج داخل المنصة', en: 'Native lead form' }
const row = (spend: number, leads: number) => ({
  reported: { spend: true, leads: true }, spend, leads, outcome: OUTCOME, outcome_label: LABEL,
})
const rows: Record<string, Record<string, unknown>> = {
  cheap: row(minResults * 50, minResults * 10),
  usual1: row(minResults * 100, minResults * 10),
  usual2: row(minResults * 100, minResults * 9),
  dear: row(minResults * 300, minResults * 10),
}
const campaigns = Object.keys(rows).map((id) => ({ id, objective: 'leads', name: `Campaign ${id}` }))
const answer = landingAnswer(campaigns, new Map(Object.entries(rows)), undefined, new Set())
const range = { from: '2026-10-01', to: '2026-10-28' }
const CURRENCY = 'SAR'

describe('the landing comparison explains itself', () => {
  it('returns, per compared group, the sample, the median and the thresholds the counts were read against', () => {
    expect(answer.comparisons).toHaveLength(1)
    const [g] = answer.comparisons
    expect(g.peers).toBe(Object.keys(rows).length)
    expect(g.peers).toBeGreaterThanOrEqual(minPeers)
    expect(g.opportunityAtMost).toBe(g.usualCost * LANDING_COMPARISON.opportunityRatio)
    expect(g.costlierAtLeast).toBe(g.usualCost * LANDING_COMPARISON.inefficientRatio)
    expect(g.zeroSpendFloor).toBe(g.usualCost * minResults)
    expect(g.flagged.filter((f) => f.kind === 'opportunity')).toHaveLength(answer.opportunity ?? -1)
    expect(new Set(g.flagged.filter((f) => f.kind !== 'opportunity').map((f) => f.id)).size).toBe(answer.inefficient ?? -1)
  })

  it.each([['ar', true], ['en', false]] as const)('opens onto period, currency, outcome, sample, reference and each reason (%s)', (_l, ar) => {
    render(<LandingComparisonChip kind="review" count={answer.inefficient ?? 0} answer={answer} ar={ar} range={range} currency={CURRENCY} />)
    const chip = screen.getByTestId('landing-inefficient')
    fireEvent.click(within(chip).getByText((_t, el) => el?.tagName === 'SUMMARY'))
    const evidence = within(chip).getByTestId('landing-comparison-evidence')

    const scope = within(evidence).getByTestId('landing-comparison-scope').textContent ?? ''
    expect(scope).toContain(range.from)
    expect(scope).toContain(range.to)
    expect(scope).toContain(CURRENCY)
    expect(scope).toContain(String(minResults))
    expect(evidence.textContent).toContain(ar ? LABEL.ar : LABEL.en)
    expect(within(evidence).getByTestId('landing-comparison-reference').textContent).toContain(String(answer.comparisons[0].peers))
    const flagged = answer.comparisons[0].flagged.filter((f) => f.kind !== 'opportunity')
    const reasons = within(evidence).getAllByTestId('landing-comparison-reason')
    expect(reasons).toHaveLength(flagged.length)
    flagged.forEach((f, i) => expect(reasons[i].textContent).toContain(`Campaign ${f.id}`))
  })

  it.each([['ar', true], ['en', false]] as const)('never calls spend waste or inefficient (%s)', (_l, ar) => {
    const { container } = render(
      <>
        <LandingComparisonChip kind="review" count={answer.inefficient ?? 0} answer={answer} ar={ar} range={range} currency={CURRENCY} />
        <LandingComparisonChip kind="opportunity" count={answer.opportunity ?? 0} answer={answer} ar={ar} range={range} currency={CURRENCY} />
      </>,
    )
    const text = container.textContent ?? ''
    expect(text).not.toMatch(/غير فعّال|غير فعال|هدر مؤكد|^هدر|inefficient|wasted/i)
  })
})
