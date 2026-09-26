import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { Delta, deltaOf, deltaTone } from './Delta'
import { SPECS } from '@/features/analytics/metricCatalog'

/**
 * UX-DELTA-PRESENTATION-001 — whether a movement is good news is the product's answer, not a caller's.
 *
 * Four components had grown their own direction rule before this existed. The cases below are the ones
 * that differed between them: a cost-per that fell, a figure the catalogue calls neutral, and a
 * movement drawn from a baseline nobody measured.
 */
describe('the canonical delta', () => {
  it('calls a cost-per that fell good news, and one that rose bad', () => {
    expect(SPECS.cpa?.invertGood).toBe(true)

    expect(deltaTone('cpa', deltaOf(8, 10))).toBe('good')
    expect(deltaTone('cpa', deltaOf(12, 10))).toBe('bad')
  })

  it('calls a result that rose good news, and one that fell bad', () => {
    expect(deltaTone('conversions', deltaOf(120, 100))).toBe('good')
    expect(deltaTone('conversions', deltaOf(80, 100))).toBe('bad')
  })

  /** Spend is `neutral` in the catalogue: more spend is neither a win nor a loss on its own. */
  it('refuses to colour a figure the catalogue calls neutral', () => {
    expect(SPECS.spend?.neutral).toBe(true)

    expect(deltaTone('spend', deltaOf(200, 100))).toBe('neutral')
    expect(deltaTone('spend', deltaOf(50, 100))).toBe('neutral')
  })

  /**
   * A movement needs two figures. An arrow from an absent or zero baseline claims a change nobody
   * measured — and every rise from nothing is "infinite", which is a division and not an insight.
   */
  it('draws nothing without a measured baseline', () => {
    expect(deltaOf(10, null)).toBeNull()
    expect(deltaOf(10, undefined)).toBeNull()
    expect(deltaOf(null, 10)).toBeNull()
    expect(deltaOf(10, 0)).toBeNull()

    const { container } = render(<Delta metric="cpa" current={10} previous={null} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('shows a movement too small to matter as flat rather than coloured', () => {
    expect(deltaTone('cpa', deltaOf(100.2, 100))).toBe('flat')
  })

  /**
   * `dir="ltr"` on the figure: an unmarked «-12%» in an Arabic layout can have its sign moved to the
   * wrong end by the bidi algorithm, and a minus that jumps is a number that lies.
   */
  it('writes the figure left to right whatever the layout', () => {
    render(<Delta metric="cpa" current={8} previous={10} testid="d" />)

    const el = screen.getByTestId('d')
    expect(el.getAttribute('data-tone')).toBe('good')
    expect(el.querySelector('[dir="ltr"]')).not.toBeNull()
  })

  it('says what it compared against when given something to say', () => {
    render(<Delta metric="cpa" current={8} previous={10} since="vs. last month" testid="d" />)

    expect(screen.getByTestId('d').textContent).toContain('vs. last month')
  })

  /**
   * The rule holds for EVERY metric the catalogue carries, not just the ones a surface happened to show.
   *
   * Five components had grown their own answer before this existed and they disagreed — so the contract
   * is asserted across the whole catalogue rather than sampled. A metric added later with `invertGood`
   * set is covered the day it lands, and one whose flag is flipped by mistake fails here rather than
   * turning a client's rising cost green on one page and red on another.
   */
  it('agrees with the catalogue for every metric in it', () => {
    const keys = Object.keys(SPECS)
    expect(keys.length).toBeGreaterThan(20)

    for (const key of keys) {
      const spec = SPECS[key]!
      const rose = deltaTone(key, 0.25)
      const fell = deltaTone(key, -0.25)

      if (spec.neutral) {
        expect(rose, `${key} is neutral and must not be coloured`).toBe('neutral')
        expect(fell, `${key} is neutral and must not be coloured`).toBe('neutral')

        continue
      }

      const [good, bad] = spec.invertGood ? [fell, rose] : [rose, fell]

      expect(good, `${key}: the good direction must read as good`).toBe('good')
      expect(bad, `${key}: the bad direction must read as bad`).toBe('bad')
    }
  })

  /** A key the catalogue never held can still state its own direction, and only that. */
  it('lets a key outside the catalogue supply the one fact the catalogue is missing', () => {
    expect(SPECS.some_provider_field).toBeUndefined()

    expect(deltaTone('some_provider_field', 0.2)).toBe('good')
    expect(deltaTone('some_provider_field', 0.2, { invertGood: true })).toBe('bad')
    expect(deltaTone('some_provider_field', 0.2, { neutral: true })).toBe('neutral')
  })
})
