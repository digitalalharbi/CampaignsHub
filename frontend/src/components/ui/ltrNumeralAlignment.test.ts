import { describe, expect, it } from 'vitest'

/**
 * KPI-ALIGNMENT-001 — a figure sits on the same side as the label above it.
 *
 * «Arabic / RTL: label + value + trend must align to the RIGHT. English / LTR: to the LEFT. The
 * NUMBER must visually sit on the same logical side as its title, not on the opposite side.»
 *
 * ## The mechanism, because it is not obvious
 *
 * `dir="ltr"` is how this product keeps «3,465.33 USD» in Latin reading order inside an Arabic page —
 * without it the currency jumps to the wrong end of the amount. But `dir` also resets the default
 * text alignment of the box it is on: a BLOCK element with `dir="ltr"` and no explicit alignment
 * aligns LEFT, whatever the page direction. So in Arabic the label sits right and its own figure
 * sits left, with the width of the card between them.
 *
 * `MetricStrip` has carried `dir="ltr"` AND `text-start` together since it was written, and its own
 * comment says why. Everything built after it copied the `dir` and not the alignment.
 *
 * ## What this flags, and what it deliberately does not
 *
 * Only BLOCK-level numerals — `block`, `flex`, `grid`. An inline `<span dir="ltr">` inside a sentence
 * inherits the paragraph's alignment and is correct as it stands; flagging those would be 379 false
 * positives over 31 real ones, and a guard that cries wolf gets an exemption list instead of a fix.
 *
 * `<td>` and `<th>` are skipped for the same reason: the cell decides, and `tnumOnCells` already
 * holds that rule from the other side.
 */
const SOURCES = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/** An element carrying `dir="ltr"`, its own alignment box, and no alignment. */
const OFFENDER = /<[a-zA-Z][^>]{0,700}?dir="ltr"[^>]{0,700}?>/gs
const ALIGNED = /\b(text-start|text-end|text-center|text-right|text-left)\b/
/**
 * What counts as «its own alignment box» for this rule.
 *
 * `block` and `grid` establish one. A bare `flex` does too — but `inline-flex` does not, and a flex
 * row that places its children with `justify-*` has already SAID where they go, so `dir` changes
 * nothing about it. Those two exclusions are what separate a KPI card from a form field: `PhoneField`
 * and `DateField` are `flex` rows in Latin order by design, and neither is a label above a figure.
 */
const OWN_BOX = /className=[^>]*\b(block|grid|flex)\b/
const NOT_A_TEXT_BOX = /\b(inline-flex|inline-grid|justify-)/
const CELL = /^<(td|th)\b/

describe('a figure in Latin reading order still sits under its own label', () => {
  const offenders: string[] = []

  for (const [path, raw] of Object.entries(SOURCES)) {
    if (path.includes('.test.')) continue

    /* Comments explain this rule in several files; reading them back would report the documentation. */
    const code = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

    for (const match of code.matchAll(OFFENDER)) {
      const tag = match[0]

      if (CELL.test(tag) || ALIGNED.test(tag) || !OWN_BOX.test(tag) || NOT_A_TEXT_BOX.test(tag)) continue

      offenders.push(`${path}: ${tag.slice(0, 80).replace(/\s+/g, ' ')}`)
    }
  }

  it('read the source it claims to guard', () => {
    expect(Object.keys(SOURCES).length).toBeGreaterThan(50)
  })

  it('has no block-level numeral that aligns opposite its label', () => {
    expect(
      offenders,
      `these elements set dir="ltr" on their own box without an alignment, so under RTL the figure `
      + `sits on the opposite side from its label — add text-start (or the alignment the design wants):\n  `
      + offenders.join('\n  '),
    ).toEqual([])
  })
})
