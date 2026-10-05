import { describe, expect, it } from 'vitest'
import { MARK_ACCEPT, MARK_FRAME, MARK_MAX_MB, MARK_SUGGESTED, markGuidance } from './markSpec'

/**
 * REPORT-IDENTITY-PROPORTION-001 — the operator is told what to upload BEFORE they upload it.
 *
 * «اضف تعليمات مثلا المقاسات المطلوبة للوقو والصيغة.» The control asked for a logo and said nothing
 * about it, so what arrived was whatever was to hand — and the shape of the artwork is what decides
 * whether the mark reads as a brand or as a sliver.
 *
 * The line has to carry four facts, in both languages, and it has to AGREE with the frame the mark
 * is actually drawn in; a promise the product then breaks is worse than no guidance.
 */
const SOURCES = import.meta.glob('/src/components/brand/BrandMark.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

describe('the brand mark guidance', () => {
  for (const [lang, ar] of [['Arabic', true], ['English', false]] as const) {
    it(`states format, size, ratio, weight and frame in ${lang}`, () => {
      const line = markGuidance(ar)

      expect(line).toContain('SVG')
      expect(line).toContain('PNG')
      expect(line).toContain(String(MARK_SUGGESTED.width))
      expect(line).toContain(String(MARK_SUGGESTED.height))
      expect(line).toContain(`${MARK_SUGGESTED.maxRatio}:1`)
      expect(line).toContain(String(MARK_MAX_MB))
      expect(line).toContain(`${MARK_FRAME.width}×${MARK_FRAME.height}`)
    })
  }

  /**
   * NUMERAL-PREFERENCE — the language of the sentence never decides the numerals.
   *
   * An Arabic sentence with Eastern Arabic digits in it would be the one place in the product that
   * disagrees with every other figure on the screen.
   */
  it('writes its numbers in Latin digits in Arabic too', () => {
    expect(markGuidance(true)).not.toMatch(/[٠-٩۰-۹]/)
  })

  /** `accept` and the sentence name the same four types. */
  it('offers exactly the types the guidance names', () => {
    expect(MARK_ACCEPT.split(',').sort()).toEqual(
      ['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp'],
    )
  })

  /**
   * The quoted frame is the frame `BrandMark` draws at its largest size.
   *
   * Read from the source rather than restated, because the whole point of quoting a frame is that it
   * is the real one — a number that drifts is guidance that lies.
   */
  it('quotes the plate BrandMark actually draws', () => {
    const source = SOURCES['/src/components/brand/BrandMark.tsx']

    expect(source, 'BrandMark moved — update this guard').toBeTruthy()
    expect(source).toContain(`max-w-[${MARK_FRAME.width}px]`)
    expect(source).toContain(`h-${MARK_FRAME.height / 4}`)
  })
})

/**
 * The printed cover answers to the same rule as the screen.
 *
 * `PrintDocument` has no Tailwind of its own — its cover is styled by a CSS string — so the plate
 * exists there as plain rules and could silently drift back to a bare ceiling. `max-width` alone is
 * what printed a tall crest as a few millimetres of colour beside a wordmark that filled the line.
 */
const PRINT = import.meta.glob('/src/features/reports/PrintDocument.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

describe('the printed cover marks', () => {
  const rule = (name: string): string => {
    const source = PRINT['/src/features/reports/PrintDocument.tsx']
    expect(source, 'PrintDocument moved — update this guard').toBeTruthy()

    const found = new RegExp(`\\.${name}\\s*\\{([^}]*)\\}`).exec(source)
    expect(found, `no .${name} rule in the print stylesheet`).not.toBeNull()

    return found![1]
  }

  for (const name of ['doc-logo', 'doc-by-logo']) {
    it(`.${name} fixes a height and bounds the width at both ends`, () => {
      const css = rule(name)

      expect(css).toMatch(/(^|[^-])height:\s*\d+px/)
      expect(css).toMatch(/min-width:\s*\d+px/)
      expect(css).toMatch(/max-width:\s*\d+px/)
      expect(css).toContain('object-fit: contain')
    })
  }
})
