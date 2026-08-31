import { describe, expect, it } from 'vitest'

import { orderRows } from './tableSort'

/** Rows as the tables pass them: platform name, spend, ROAS — some of it absent. */
const ROWS = [
  ['ميتا', 300, 2.5],
  ['سناب شات', 900, null],
  ['تيك توك', 100, 4.0],
  ['جوجل', null, 1.2],
]

describe('ordering an analytics table', () => {
  it('sorts a numeric column descending', () => {
    expect(orderRows(ROWS, 1, 'desc').map((i) => ROWS[i][0])).toEqual(['سناب شات', 'ميتا', 'تيك توك', 'جوجل'])
  })

  it('sorts the same column ascending', () => {
    expect(orderRows(ROWS, 1, 'asc').map((i) => ROWS[i][0])).toEqual(['تيك توك', 'ميتا', 'سناب شات', 'جوجل'])
  })

  /**
   * The rule that matters most. «This platform does not report ROAS» is not the worst ROAS, and it
   * is not the best either — sorting a null to the top of an ascending column is how an absence gets
   * read as a result, which is the confusion the money contract exists to prevent one layer down.
   */
  it('puts absent figures last whichever way the column points', () => {
    expect(orderRows(ROWS, 2, 'desc').map((i) => ROWS[i][0]).at(-1)).toBe('سناب شات')
    expect(orderRows(ROWS, 2, 'asc').map((i) => ROWS[i][0]).at(-1)).toBe('سناب شات')
    expect(orderRows(ROWS, 1, 'asc').map((i) => ROWS[i][0]).at(-1)).toBe('جوجل')
  })

  it('sorts text naturally, so «Campaign 2» comes before «Campaign 10»', () => {
    const named = [['Campaign 10'], ['Campaign 2'], ['Campaign 1']]
    expect(orderRows(named, 0, 'asc').map((i) => named[i][0])).toEqual(['Campaign 1', 'Campaign 2', 'Campaign 10'])
  })

  it('keeps equal rows in their original order, so re-sorting does not shuffle them', () => {
    const tied = [['a', 5], ['b', 5], ['c', 5]]
    expect(orderRows(tied, 1, 'desc').map((i) => tied[i][0])).toEqual(['a', 'b', 'c'])
    expect(orderRows(tied, 1, 'asc').map((i) => tied[i][0])).toEqual(['a', 'b', 'c'])
  })

  it('returns the same rows, never a different set', () => {
    for (const dir of ['asc', 'desc'] as const) {
      for (let c = 0; c < 3; c++) {
        expect([...orderRows(ROWS, c, dir)].sort()).toEqual([0, 1, 2, 3])
      }
    }
  })

  it('handles a table where a whole column is absent', () => {
    const none = [['a', null], ['b', null]]
    expect(orderRows(none, 1, 'desc').map((i) => none[i][0])).toEqual(['a', 'b'])
  })
})
