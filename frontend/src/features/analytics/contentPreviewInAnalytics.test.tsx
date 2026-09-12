import { describe, expect, it } from 'vitest'

/**
 * ANALYTICS-CONTENT-PREVIEW-001 — the Content performance table SHOWS the content.
 *
 * «Every creative/ad-content row must show a small real thumbnail beside the content/ad name …
 * clicking the thumbnail/name opens a professional modal.» The column printed a name and nothing
 * else, on the one tab whose entire question is «which creative did best».
 *
 * The row already arrives from the LIBRARY's own endpoint — `listCreatives`, the same call the
 * Content page makes — so the preview envelope the server computed through `CreativePresenter` was
 * in hand and simply never drawn.
 *
 * ## Why this reads the source
 *
 * The claim is about REUSE: that this table draws the canonical poster and opens the canonical
 * dialog rather than growing a second preview pipeline. «Do NOT create another preview or metrics
 * pipeline» is the requirement, and a rendering test cannot tell a canonical `AdPoster` from a
 * hand-rolled `<img>` that happens to show the same file today.
 */
const SOURCES = import.meta.glob('/src/features/analytics/AnalyticsPage.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const source = Object.values(SOURCES)[0] ?? ''

describe('the Analytics content table', () => {
  it('was read at all', () => {
    expect(source.length).toBeGreaterThan(1000)
  })

  it('draws the canonical poster beside the content name', () => {
    expect(source).toContain("from '@/features/content/AdPoster'")
    expect(source).toContain('<AdPoster')

    /* The row's OWN preview envelope — not a URL picked out of the payload by hand. */
    expect(source).toContain('preview={cr.preview}')
  })

  it('opens the canonical dialog rather than a second one', () => {
    expect(source).toContain("from '@/features/content/AdPreviewDialog'")
    expect(source).toContain('<AdPreviewDialog')
  })

  /**
   * And it grows no second preview reader.
   *
   * A bare `<img src=` anywhere in this file would mean somebody answered «what does this ad look
   * like» a second time — the defect AD-PREVIEW-001 spent three units removing, where a share link
   * was rendered as an image and fetched 260KB of HTML into an `<img>`.
   */
  it('does not hand-roll an image tag for a creative', () => {
    const code = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

    expect(code).not.toMatch(/<img\s/)
  })

  /**
   * The modal's figures come from the ROW, so the two cannot disagree.
   *
   * `AdPreviewDialog` takes `figures` already formatted by the surface that owns them. A dialog that
   * re-derived spend would be a second metrics pipeline, which the requirement names explicitly.
   */
  it('hands the dialog the row’s own figures', () => {
    expect(source).toMatch(/figures=\{\[/)
    expect(source).toContain("{ label: 'CTR', value: rateOrDash(openCreative.metrics?.ctr ?? null) }")
  })

  /**
   * And the modal draws the SHARED trend component, not a series of its own.
   *
   * «The modal must include a performance trend chart … do NOT create another metrics pipeline.»
   * `CreativeTrend` is the block `CreativeDetailPage` has drawn since it shipped, moved so it can be
   * asked for from anywhere; a second series derived from whatever this surface happened to hold
   * would differ from the detail page by whatever the two windows disagreed about.
   */
  it('draws the shared creative trend inside the modal', () => {
    expect(source).toContain("from '@/features/content/CreativeTrend'")
    expect(source).toContain('<CreativeTrend')

    /* The surface's OWN window and currency — the component never decides what «this period» means. */
    expect(source).toContain('window={{ from: range.from, to: range.to }}')
  })
})
