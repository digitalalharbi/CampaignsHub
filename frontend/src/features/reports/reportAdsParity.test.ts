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
const TREE: Record<string, string> = import.meta.glob(['/src/features/reports/*.tsx', '/src/features/reports/live/*.tsx'], {
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
    // The live link composes its modes from `live/`; the dashboard mode is where the ranked ads render.
    expect(file('live/LiveViews.tsx')).toContain('<ReportAdsSection')
  })

  it('reads the same payload key everywhere, including the printed document', () => {
    for (const name of ['InteractiveReport.tsx', 'live/LiveViews.tsx', 'PrintDocument.tsx']) {
      expect(file(name), name).toMatch(/ads_absent_reason/)
    }
    expect(file('PrintDocument.tsx')).toMatch(/data\.ads/)
  })

  /**
   * The printed document must not invent a picture either — and must not LOSE one.
   *
   * A grey box in a PDF a client keeps reads as a broken export, so the absent states print their
   * sentence. This asserted the state check the document used to spell out by hand:
   * `state === 'available' ? thumbnail_url ?? image_url : null`.
   *
   * That chain was also the defect. `readPreview` knows three things it does not — a video whose
   * poster is the only thing that arrived, a collection whose hero is a film, a catalog ad that is
   * missing nothing by design — so the printed deck could show an empty cell for a creative the
   * library draws a picture of, which is «one creative, same period, same scope» failing on the one
   * document a client keeps. The state check lives inside the shared resolver now, so the claim is
   * made against THAT rather than against a copy of it.
   */
  it('prints a picture only where the platform actually gave one', () => {
    const print = file('PrintDocument.tsx')

    expect(print, 'the printed deck resolves its own poster again').toContain('posterSource(reading)')
    expect(print).toContain('readPreview(')
    expect(print).toContain('doc-ad-absent')
  })
})
