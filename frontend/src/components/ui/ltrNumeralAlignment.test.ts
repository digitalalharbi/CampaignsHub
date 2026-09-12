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

/** Any opening tag that carries `dir="ltr"`. */
const TAGGED = /<([a-zA-Z][a-zA-Z0-9]*)\b[^>]{0,900}?dir="ltr"[^>]{0,900}?>/gs

/** Lays out as a block — so `dir` on it re-bases the alignment of everything inside. */
const BLOCK_BOX = /className=[^>]*\b(block|grid|flex)\b/
const INLINE_BOX = /\b(inline-flex|inline-grid|inline-block)\b/

/** A typing context of its own, not a figure aligned against a label. */
const CONTROL = /^(input|textarea|select|code|pre|bdi)$/

/**
 * Blocks that carry `dir="ltr"` for a reason that is not this defect, and the reason.
 *
 * Each is asserted below to still contain one, so the list cannot be satisfied by deleting an entry
 * whose file has already been fixed — the same rule the table contract follows. Anything not here is
 * a figure sitting across the card from its own label.
 */
const EXEMPT: Record<string, string> = {
  '/src/components/ui/PhoneField.tsx': 'a control GROUP: the country code is typed before the number, and a caret that starts at the right edge is wrong for a phone number in any language',
  '/src/components/ui/DateField.tsx': 'the same, for a date whose segments run year → month → day whatever the page direction is',
  '/src/features/auth/OtpField.tsx': 'six boxes filled left to right — the order a person reads a code out of an SMS',
  '/src/features/dev/DevStatusPage.tsx': 'an internal build-status page with no Arabic copy and no metric cards on it',
}

describe('a figure reads in Latin order without moving its block', () => {
  const offenders: string[] = []
  const exempted = new Set<string>()

  for (const [path, raw] of Object.entries(SOURCES)) {
    if (path.includes('.test.')) continue

    /* Several files explain this rule in prose; reading it back would report the documentation. */
    const code = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

    for (const match of code.matchAll(TAGGED)) {
      const [tag, element] = match

      if (CONTROL.test(element) || INLINE_BOX.test(tag) || !BLOCK_BOX.test(tag)) continue
      if (path in EXEMPT) {
        exempted.add(path)
        continue
      }

      offenders.push(`${path}: ${tag.slice(0, 90).replace(/\s+/g, ' ')}`)
    }
  }

  it('read the source it claims to guard', () => {
    expect(Object.keys(SOURCES).length).toBeGreaterThan(50)
  })

  /* An exemption whose file no longer carries one is a line nobody can act on — see the list. */
  it('has no exemption that has already been fixed', () => {
    expect([...Object.keys(EXEMPT)].filter((p) => !exempted.has(p))).toEqual([])
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
