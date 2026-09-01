import { describe, expect, it } from 'vitest'

/**
 * MONEY-SCOPE-TRUTH-001 — one document, one way of saying money.
 *
 * The live shared report printed «$7,420» in its «direct against blended» panel while every table on
 * the same page printed «7.75K USD», and the printed PDF restated the same figures in the symbol
 * form again. Observed on the owner's own link.
 *
 * That split is not cosmetic. A reader comparing the strip with the table below it has to work out
 * whether the two are even in the same units — a question a report must never make anybody ask — and
 * «$» is the symbol of a dozen currencies, so the symbol form is the one notation nobody can check.
 * The canonical helpers also refuse to print a figure whose currency the scope cannot state, where
 * `Intl` with `style: 'currency'` will happily dress a bare number in a symbol it was handed.
 *
 * Scanned over the SOURCE rather than over rendered output, because a rendering test only covers the
 * surfaces somebody thought to render — and this defect lived on the one panel that had no test.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

describe('how a report says money', () => {
  it('never formats money with a currency symbol of its own', () => {
    /*
     * Comment lines are skipped.
     *
     * The two files this guard was written for now explain the old formatting in their own
     * docblocks, and a scan that read prose would fail on its own rationale — or force the
     * rationale out of the code, which is worse. What is asserted is about executed lines, so
     * only executed lines are read.
     */
    const offenders = Object.entries(TREE)
      .filter(([path]) => !/\.test\.tsx?$/.test(path))
      .filter(([, source]) =>
        source
          .split('\n')
          .filter((line) => {
            const trimmed = line.trim()
            return !trimmed.startsWith('*') && !trimmed.startsWith('//') && !trimmed.startsWith('/*')
          })
          .some((line) => /style:\s*['"]currency['"]/.test(line)),
      )
      .map(([path]) => path)

    expect(
      offenders,
      'a surface formatted money its own way — use money() / moneyExact() from features/analytics/format',
    ).toEqual([])
  })
})
