import { describe, expect, it } from 'vitest'

/**
 * TABLE-NUMERIC-ALIGNMENT-001 — `.tnum` on a `<td>` puts the heading and its figures on opposite
 * edges of the same column.
 *
 * `.tnum` carries `direction: ltr` beside its tabular numerals (`tokens.css`). That is right for the
 * numeral it wraps and wrong for the box that holds it: on a `<td>` it makes the CELL an LTR box, so
 * a header inheriting the Arabic page resolves `text-end` to the LEFT while the figure inside the
 * cell resolves the same `text-end` to the RIGHT. «الإنفاق» at one edge, its money at the other.
 *
 * The geometric sweep could not see this for most of its life — a `th` and its cells share one
 * column, so their box centres coincide by construction whatever the text inside does — and it is
 * invisible to type checking and to every render test that does not read computed styles.
 *
 * A grep is what finds the class. The sweep proves the RESULT on the surfaces it walks; this proves
 * the CAUSE cannot come back on a surface nobody thought to add to it.
 */

/*
 * Read through Vite rather than `node:fs`: this suite's tsconfig carries no Node types, and
 * `import.meta.glob` is eager and resolved at build time, so the keys are repository paths in every
 * runner. The same reader `numeralLiterals.test.ts` uses, for the same reason.
 */
const SOURCES: Record<string, string> = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/**
 * `<td>` carrying `.tnum` — unless the same cell is explicitly CENTRED.
 *
 * Centre is the one alignment that resolves to the same edge in both writing directions, so a
 * centred figure cannot be moved by the LTR box `.tnum` creates. That is not a loophole: it is why
 * `MetricTable`, the canonical primitive, is immune — it centres numeric columns on the header AND
 * the cell — and it is why the geometric sweep measures sixty-eight columns through it with zero
 * drift. Anything else (`text-start`, `text-end`, or no alignment at all, which inherits `start`)
 * is direction-relative and therefore moves.
 */
const TD_CLASS = /<td[^>]*className=(?:"([^"]*)"|\{`([^`]*)`\})/g

const carriesTnumUncentred = (source: string) => {
  for (const match of source.matchAll(TD_CLASS)) {
    const classes = (match[1] ?? match[2] ?? '').split(/\s+/)
    if (classes.includes('tnum') && !classes.includes('text-center')) return true
  }

  return false
}

describe('tabular numerals belong to the numeral, not to the cell', () => {
  it('no <td> carries the tnum class unless it is centred', () => {
    const offenders = Object.entries(SOURCES)
      .filter(([, source]) => carriesTnumUncentred(source))
      .map(([path]) => path.replace(/^\//, ''))

    expect(
      offenders,
      'these cells set their own direction, which moves their figures out from under their headings — '
        + 'put the class on a <span> inside the cell instead',
    ).toEqual([])
  })
})
