import { describe, expect, it } from 'vitest'
import { CARD_GAP, CARD_PAD, CARD_PAD_DENSE, METRIC_LABEL, METRIC_VALUE, METRIC_VALUE_DENSE, PAGE_TITLE } from './scale'

/**
 * UX-KPI-PRESENTATION-001 — the cards read the scale, they do not each decide it.
 *
 * The defect this exists against is not a wrong size. It is two components agreeing by coincidence:
 * `StatCard` and `MetricCard` both chose `text-2xl` for the value and `text-xs` for the label, which
 * looked consistent right up until one of them was touched. A row built from both then had two
 * different figures at two different weights, and nothing in either file said they were meant to
 * match.
 *
 * So the check is on the SOURCE: the components that draw a labelled figure import the scale, and
 * they do not carry a competing literal. A new card that hard-codes its own size fails here rather
 * than six months later in a screenshot.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const read = (path: string): string => {
  const source = TREE['/' + path]
  if (source === undefined) throw new Error(`${path} is not in the source tree — this guard reads a file that moved`)
  return source
}

/** The components whose whole job is a labelled figure. */
const CARDS = ['src/components/ui/StatCard.tsx', 'src/components/ui/MetricStrip.tsx']

describe('the KPI surfaces share one scale', () => {
  for (const path of CARDS) {
    it(`${path} takes its sizes from @/styles/scale`, () => {
      expect(read(path)).toContain("from '@/styles/scale'")
    })

    /*
     * `text-2xl` on its own line in one of these files is the old decision coming back. The scale's
     * own constants may contain it — that is where it belongs — so the check is on the call sites.
     */
    it(`${path} carries no competing value size`, () => {
      const offenders = read(path)
        .split('\n')
        .filter((line) => /text-2xl|text-\[2[0-9]px\]|text-3xl/.test(line))
        .filter((line) => !/METRIC_VALUE|scale'/.test(line))

      expect(offenders, 'a figure size belongs in @/styles/scale, not in the card').toEqual([])
    })
  }

  /** The page title is one decision too, and `PageIntro` is the only place allowed to make it. */
  it('the page title comes from the scale', () => {
    expect(read('src/components/ui/PageIntro.tsx')).toContain('PAGE_TITLE')
  })

  /**
   * The values themselves, asserted rather than assumed.
   *
   * A constant that silently lost its responsive step would pass every «imports the scale» check
   * above while shipping a phone-sized figure to a desktop. These four lines are the design
   * decision, written down: a lead figure grows above `sm`, a dense one grows less, the label sits
   * one step above caption text, and a card's padding is not the same on a phone and a desktop.
   */
  it('states the steps it is there to hold', () => {
    // MOBILE-DENSITY-001 — one step smaller on a phone, the old sizes from `lg` up.
    expect(METRIC_VALUE).toContain('text-xl')
    expect(METRIC_VALUE).toContain('lg:text-[32px]')
    expect(METRIC_VALUE_DENSE).toContain('text-lg')
    expect(METRIC_VALUE_DENSE).toContain('lg:text-[26px]')
    expect(METRIC_LABEL).toContain('text-xs')
    expect(PAGE_TITLE).toContain('lg:text-[28px]')
    expect(CARD_PAD).toBe('p-3 sm:p-4 lg:p-5')
    expect(CARD_PAD_DENSE).toBe('p-2.5 sm:p-3.5 lg:p-4')
    expect(CARD_GAP).toBe('gap-2 sm:gap-3 lg:gap-4')
  })

  /** Every figure is tabular: a column of numbers that shifts as digits change is unreadable. */
  it('sets tabular numerals on every figure size', () => {
    for (const size of [METRIC_VALUE, METRIC_VALUE_DENSE]) expect(size).toContain('tnum')
  })
})
