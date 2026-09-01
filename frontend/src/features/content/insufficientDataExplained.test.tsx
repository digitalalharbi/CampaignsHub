import { describe, expect, it } from 'vitest'

/**
 * INSUFFICIENT-DATA-EXPLAINED-001 — «insufficient data» is the one verdict that must say why.
 *
 * ## What the reader concluded instead
 *
 * `CreativeFatigue` has always computed exactly what was missing — fewer than seven active days, no
 * previous window to compare against, too few impressions for movement to be a trend — and returns
 * it as `reason_ar`/`reason_en`. The library dropped it and printed the bare chip.
 *
 * So the one status that exists to say «we cannot tell you yet» did not say why, and reads as a
 * data-quality problem the reader should go and fix. It is usually a creative that started on
 * Thursday. Somebody chasing a sync bug that does not exist is the cost of a two-word chip.
 *
 * ## Why a source guard and not only a render test
 *
 * The chip appears on five surfaces, and the defect is one of OMISSION — nothing throws, nothing
 * looks broken, the label is even correct. A render test proves the surfaces it covers; the scan
 * proves nobody adds a sixth surface that quietly drops the reason again.
 */
const TREE: Record<string, string> = import.meta.glob('/src/features/content/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** Comments are stripped: the files that FIXED this describe the defect at length. */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1')
}

describe('a verdict that declines to judge says what was missing', () => {
  /**
   * Every surface that prints the fatigue LABEL also reads the fatigue REASON.
   *
   * Named by behaviour rather than by a list of files, so a new surface is caught the day it is
   * written rather than the day somebody remembers to update a list.
   */
  it('is read wherever the verdict is printed', () => {
    const offenders = Object.entries(TREE)
      .map(([path, source]) => [path.replace(/^\/+/, ''), withoutComments(source)] as const)
      .filter(([path]) => !/\.test\.tsx$/.test(path))
      .filter(([, source]) => /FATIGUE_LABEL\[/.test(source))
      .filter(([, source]) => !/fatigue\.reason_(ar|en)/.test(source))
      .map(([path]) => path)

    expect(
      offenders,
      'these surfaces print the verdict and drop the reason it was reached:\n  ' + offenders.join('\n  '),
    ).toEqual([])
  })

  /** And the scan is reading something: a glob that matched nothing would agree forever. */
  it('finds the surfaces that print it', () => {
    const printers = Object.values(TREE).filter((source) => /FATIGUE_LABEL\[/.test(source))

    expect(printers.length).toBeGreaterThan(2)
  })
})
