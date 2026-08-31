import { describe, expect, it } from 'vitest'
import { ratio } from './format'

/**
 * TYPOGRAPHY-PRODUCT-POLISH-001 — one multiplier, spelled one way, everywhere.
 *
 * ROAS read «15.36x» on the campaigns page and «15.36×» on the same customer's analytics tab,
 * because `ratio()` defaulted to the LETTER x and four surfaces passed the multiplication sign
 * instead. Each choice was reasonable alone; the set was a product that cannot agree with itself
 * about a number it prints on nearly every screen.
 *
 * This scans the source for a surface writing its own suffix — which is how the second spelling got
 * in, and is the only way a third one can.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/**
 * The report DECK is exempt and says why: it is a printed document assembled from raw values with
 * its own locale-fixed number formatter (`nfmt`), not from `format.ts`. It uses the same glyph, and
 * this scan asserts that rather than pretending it calls the helper.
 */
const DECK = 'src/features/reports/PrintDocument.tsx'

describe('the multiplier glyph', () => {
  it('is the multiplication sign, from one function', () => {
    expect(ratio(15.357)).toBe('15.36×')
    expect(ratio(null)).toBe('—')
  })

  it('is not spelled with a letter anywhere in the product', () => {
    const offenders = Object.entries(TREE)
      .map(([path, source]) => [path.replace(/^\/+/, ''), source] as const)
      .filter(([path]) => !/\.test\.tsx?$/.test(path))
      .filter(([, source]) => /\}x`|\}x'|\}x"/.test(source))
      .map(([path]) => path)

    expect(offenders, 'a multiplier is «×» and comes from ratio() — this file spells its own').toEqual([])
  })

  it('holds the printed deck to the same glyph', () => {
    const deck = TREE['/' + DECK]
    expect(deck, 'the deck moved — point this test at it').toBeDefined()
    expect(deck).toContain('}×`')
    expect(deck).not.toContain('}x`')
  })

  it('reads the source tree', () => {
    expect(Object.keys(TREE).length).toBeGreaterThan(200)
  })
})
