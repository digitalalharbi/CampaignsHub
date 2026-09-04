import { describe, expect, it } from 'vitest'
import { CROSS_OBJECTIVE, clientKpiKeys } from './clientKpis'

/**
 * CLIENT-FACING-PRESENTATION-001 — the executive block answers the questions THIS report raises.
 *
 * Observed on a real link: «Results 581» beside «Purchases 581», and no cost-per figure anywhere —
 * the one the hard rule names first. A fixed list of eight metrics cannot be right for an awareness
 * report and a lead-generation report at once, and it was not right for either.
 */
const RENDERABLE = new Set([
  'spend', 'impressions', 'clicks', 'conversions', 'purchases', 'revenue', 'roas', 'cpa',
  'reach', 'frequency', 'cpm', 'cpc', 'leads', 'cpl', 'add_to_cart',
])

const path = (over: Record<string, unknown> = {}) => ({
  spend: 1000,
  headline_metrics: ['spend', 'conversions', 'cpa', 'revenue', 'roas'],
  ...over,
})

describe('the cards a client link shows', () => {
  /** The operator chose; nothing here may second-guess that — LIVEREP-002. */
  it('shows exactly what the operator selected, when they selected', () => {
    const keys = clientKpiKeys({ metrics: ['spend', 'roas'], objective_performance: { paths: [path()] } }, RENDERABLE)

    expect(keys).toEqual(['spend', 'roas'])
  })

  /** One path carrying the spend: that path's own metrics, which is what the server already decided. */
  it('follows the objective when the spend sits on one path', () => {
    const keys = clientKpiKeys(
      { objective_performance: { paths: [path(), path({ spend: 0, headline_metrics: ['impressions', 'cpm'] })] } },
      RENDERABLE,
    )

    expect(keys).toContain('cpa')
    expect(keys, 'a path with no spend is not what this report is about').not.toContain('cpm')
  })

  it('judges an awareness report on reach and CPM, not on add-to-cart', () => {
    const keys = clientKpiKeys(
      { objective_performance: { paths: [path({ headline_metrics: ['spend', 'impressions', 'reach', 'frequency', 'cpm'] })] } },
      RENDERABLE,
    )

    expect(keys).toEqual(['spend', 'impressions', 'reach', 'frequency', 'cpm'])
    expect(keys).not.toContain('add_to_cart')
  })

  /**
   * A CPA averaged over a brand campaign and a sales campaign is the blend the product refuses
   * everywhere else: it makes the brand campaign look expensive at a job it was never bought to do.
   */
  it('states no cost-per when the spend spans objectives', () => {
    const keys = clientKpiKeys(
      {
        objective_performance: {
          paths: [path(), path({ headline_metrics: ['spend', 'impressions', 'cpm'] })],
        },
      },
      RENDERABLE,
    )

    expect(keys).toEqual(CROSS_OBJECTIVE)
    for (const cost of ['cpa', 'cpm', 'cpc', 'cpl']) expect(keys).not.toContain(cost)
  })

  /** Spend is the first question whatever the objective, even when the path lists it fourth. */
  it('leads with spend', () => {
    const keys = clientKpiKeys(
      { objective_performance: { paths: [path({ headline_metrics: ['conversions', 'cpa', 'spend'] })] } },
      RENDERABLE,
    )

    expect(keys[0]).toBe('spend')
    expect(keys.filter((k) => k === 'spend'), 'spend once, not twice').toHaveLength(1)
  })

  /** «Results 581» and «Purchases 581» — one figure, two names, and the reader does the reconciling. */
  it('drops a card that is another card under a second name', () => {
    const keys = clientKpiKeys(
      {
        objective_performance: { paths: [path({ headline_metrics: ['spend', 'conversions', 'purchases'] })] },
        totals: { conversions: 581, purchases: 581 },
      },
      RENDERABLE,
    )

    expect(keys).toEqual(['spend', 'conversions'])
  })

  /** …and keeps both when they genuinely differ, because then they are two counts. */
  it('keeps both when the two counts disagree', () => {
    const keys = clientKpiKeys(
      {
        objective_performance: { paths: [path({ headline_metrics: ['spend', 'conversions', 'purchases'] })] },
        totals: { conversions: 700, purchases: 581 },
      },
      RENDERABLE,
    )

    expect(keys).toEqual(['spend', 'conversions', 'purchases'])
  })

  /** A metric the block cannot draw is not offered as a blank card. */
  it('offers no card it cannot render', () => {
    const keys = clientKpiKeys(
      { objective_performance: { paths: [path({ headline_metrics: ['spend', 'video_thruplays'] })] } },
      RENDERABLE,
    )

    expect(keys).toEqual(['spend'])
  })

  /** A link with no objective breakdown at all still gets the safe set rather than nothing. */
  it('falls back to the cross-objective set', () => {
    expect(clientKpiKeys({}, RENDERABLE)).toEqual(CROSS_OBJECTIVE)
  })
})
