import { describe, expect, it } from 'vitest'

/**
 * REPORT-AD-PREVIEW-001 — the deck, the client's link and the PDF show the SAME ads.
 *
 * Parity here is not a screenshot comparison; it is a structural claim that can actually be held: all
 * three read the ads from one payload key, and the two that render interactively use one component.
 * The PDF cannot — it is static markup rendered by Chromium, with no hover, no dialog and no state
 * machine — so what this asserts of it is that it reads the same key and prints the same reason when
 * a picture is missing.
 *
 * The failure this prevents is quiet and expensive: three surfaces drifting until a client's link
 * shows one «best ad» and the PDF attached to the same email shows another.
 */
const TREE: Record<string, string> = import.meta.glob('/src/features/reports/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const file = (name: string): string => {
  const source = TREE[`/src/features/reports/${name}`]
  expect(source, `${name} moved — point this test at it`).toBeDefined()
  return source
}

describe('the ads section, across the three surfaces', () => {
  it('is one component in the deck and in the client’s link', () => {
    expect(file('InteractiveReport.tsx')).toContain('<ReportAdsSection')
    expect(file('LiveSharedReport.tsx')).toContain('<ReportAdsSection')
  })

  it('reads the same payload key everywhere, including the printed document', () => {
    for (const name of ['InteractiveReport.tsx', 'LiveSharedReport.tsx', 'PrintDocument.tsx']) {
      expect(file(name), name).toMatch(/ads_absent_reason/)
    }
    expect(file('PrintDocument.tsx')).toMatch(/data\.ads/)
  })

  /**
   * The printed document must not invent a picture either.
   *
   * A grey box in a PDF a client keeps reads as a broken export, so the absent states print their
   * sentence — and an `<img>` is emitted only for a preview the presenter called `available`.
   */
  it('prints a picture only where the platform actually gave one', () => {
    const print = file('PrintDocument.tsx')

    expect(print).toContain("preview?.state === 'available'")
    expect(print).toContain('doc-ad-absent')
  })
})
