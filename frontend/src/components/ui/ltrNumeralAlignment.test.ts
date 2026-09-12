import { describe, expect, it } from 'vitest'

/**
 * KPI-ALIGNMENT-002 — reading order is inline; alignment is block. `dir` does both, so it goes inline.
 *
 * ## What KPI-ALIGNMENT-001 got wrong
 *
 * «56.3K SAR» inside an Arabic page needs Latin reading order, and the product got it by putting
 * `dir="ltr"` on the element holding the figure. But `dir` also re-bases every logical property on
 * that element: `text-align: start` inside a `dir="ltr"` box resolves to LEFT, whatever the page
 * direction is.
 *
 * So in Arabic the label sat at the right edge of its card and its own figure at the left, a card's
 * width apart. The previous guard concluded that the missing piece was `text-start` and made
 * twenty-four surfaces declare it — which is the SAME left alignment, written explicitly. Every
 * check passed and the screen did not change. The owner reported the defect again from a screenshot,
 * which is the only place it was ever visible.
 *
 * ## The rule this holds instead
 *
 * A block that contains a figure keeps the PAGE's direction, so its `text-start` means the right
 * edge in Arabic and the left in English — label, value and trend on one edge. The digits are
 * isolated inline, with `<Num>` (a `<bdi dir="ltr">`), which fixes reading order and changes no
 * alignment.
 *
 * So: no `dir="ltr"` on anything that lays out as a block.
 *
 * ## The exceptions, and why each is not this defect
 *
 * A form CONTROL is its own typing context — a phone number, a URL, an ad-account id are typed left
 * to right and a caret that starts at the right edge is wrong for them. `<code>`/`<pre>` are the
 * same argument for a payload nobody is aligning against a label. An `inline-*` box does not
 * establish its own alignment, so `dir` on it reorders and nothing moves.
 */
const SOURCES = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

/**
 * Any opening tag that re-bases its own direction — by ATTRIBUTE or by CLASS.
 *
 * `.tnum` used to set `direction: ltr` as well as shaping digits, so it did exactly what
 * `dir="ltr"` does and this guard could not see it: eighty-six blocks carried it, and the content
 * summary's figures measured 134px from their labels with no `dir` attribute anywhere near them.
 * The class does one job now, and this keeps watching for the pattern in case a second one is added.
 */
const TAGGED = /<([a-zA-Z][a-zA-Z0-9]*)\b[^>]{0,900}?(?:dir="ltr"|\bdirection-ltr\b)[^>]{0,900}?>/gs

/**
 * Lays out as a block — so `dir` on it re-bases the alignment of everything inside.
 *
 * Two ways to be one, and the first draft of this guard only knew the second. A `<dd>`, a `<div>`, a
 * `<p>` is a block because of what it IS; `block`/`grid`/`flex` in the class list is a block because
 * of what it was told to be. Checking only the class list missed the Content card's own figure —
 * `<dd className="tabular-nums" dir="ltr">` under a `<dt>` — which is the first surface the owner
 * named. A guard that knows one of the two ways is a guard that passes the reported defect.
 */
const NATIVE_BLOCK = /^(div|p|dd|dt|dl|li|ul|ol|section|article|header|footer|main|aside|figure|figcaption|blockquote|td|th|h[1-6])$/
const BLOCK_CLASS = /className=[^>]*\b(block|grid|flex)\b/
const INLINE_BOX = /\b(inline-flex|inline-grid|inline-block)\b/

/**
 * A FIGURE, which is what the owner's contract is about — not every block that reads left to right.
 *
 * «Every metric is one logical block: label, value + unit, optional trend. They must share the same
 * alignment edge.» That is a rule about figures under labels. An English paragraph inside an Arabic
 * admin page is a different thing: it is prose in the other language, and left-aligning it is
 * correct rather than a defect, so sweeping it would be changing something that is already right.
 *
 * Two ways to be a figure here. A tabular-numeral class says so outright. A `<dd>` says so
 * structurally: it is the VALUE half of a definition list, and its `<dt>` is the label sitting
 * directly above it — which is exactly the pair the owner reported sitting on opposite edges.
 */
const IS_A_FIGURE = /\b(tnum|tabular-nums)\b/

/** A typing context of its own, not a figure aligned against a label. */
const CONTROL = /^(input|textarea|select|code|pre|bdi)$/

/*
 * There is no exemption list any more, and that is the narrowing rather than a relaxation.
 *
 * There used to be four: `PhoneField`, `DateField`, `OtpField` and the dev status page. Each was a
 * control group or an English-only internal page — real reasons, and none of them a figure under a
 * label. Once the rule asks «is this a FIGURE», none of the four is in scope at all, so the list
 * describing why they are allowed describes nothing. A list that has stopped doing work is the kind
 * a later reader trusts and a later author extends.
 */

describe('a figure reads in Latin order without moving its block', () => {
  const offenders: string[] = []

  for (const [path, raw] of Object.entries(SOURCES)) {
    if (path.includes('.test.')) continue

    /* Several files explain this rule in prose; reading it back would report the documentation. */
    const code = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

    for (const match of code.matchAll(TAGGED)) {
      const [tag, element] = match

      const isBlock = NATIVE_BLOCK.test(element) || BLOCK_CLASS.test(tag)

      if (CONTROL.test(element) || INLINE_BOX.test(tag) || !isBlock) continue
      if (!IS_A_FIGURE.test(tag) && element !== 'dd') continue

      offenders.push(`${path}: ${tag.slice(0, 90).replace(/\s+/g, ' ')}`)
    }
  }

  it('read the source it claims to guard', () => {
    expect(Object.keys(SOURCES).length).toBeGreaterThan(50)
  })

  it('puts no dir="ltr" on a block box', () => {
    expect(
      offenders,
      'These elements lay out as blocks and carry dir="ltr", which re-bases their alignment: under\n'
      + 'RTL the figure lands on the opposite edge from its label. Keep the block in the page\'s\n'
      + 'direction and wrap the value in <Num> from @/components/ui/Num instead:\n  '
      + offenders.join('\n  '),
    ).toEqual([])
  })
})
