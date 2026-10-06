import { describe, expect, it } from 'vitest'

/**
 * CONTENT-VIDEO-RETENTION-001 — the section is drawn only where there is a watch-through.
 *
 * A source guard rather than a render test. `CreativeDetailPage` mounts a chart stack, a media
 * viewer and four queries, and what is being asserted here is one decision: the section is gated on
 * the server having sent stages, so an image creative gets no empty chart. A render test of this page
 * asserts that decision through several hundred lines that have nothing to do with it.
 *
 * The decisions themselves — which stages, which nulls, which rates — are held by
 * `CreativeVideoRetentionTest`, against the builder that makes them.
 */
const SOURCE: Record<string, string> = import.meta.glob('/src/features/content/CreativeDetailPage.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

const PAGE = SOURCE['/src/features/content/CreativeDetailPage.tsx'] ?? ''

describe('the video retention section', () => {
  it('is gated on the server having sent stages, never merely on the key existing', () => {
    // `video_retention && …` would render an empty chart for an image creative, which is the whole
    // thing this gate prevents.
    expect(PAGE).toMatch(/\(data\.video_retention\?\.stages\.length \?\? 0\) > 0/)
  })

  it('passes the counts through without coercing a missing quartile to zero', () => {
    const section = PAGE.slice(PAGE.indexOf('creative-video-retention'))

    // FUNNEL-NULL-001: a hole is not a cliff. `count: s.count ?? 0` would draw a video everybody
    // abandoned at the first quarter and then finished anyway.
    expect(section).not.toMatch(/count:\s*s\.count\s*\?\?/)
    expect(section).toMatch(/count:\s*s\.count,/)
  })

  it('claims no cost per quartile', () => {
    const section = PAGE.slice(PAGE.indexOf('creative-video-retention'))

    // The spend bought the impression, not the moment somebody stopped watching.
    expect(section).toMatch(/cost_per:\s*null,/)
  })

  it('names the quartiles the platform did not report', () => {
    expect(PAGE).toContain('creative-video-retention-missing')
  })

  it('draws it through the shared chart layer rather than its own bars', () => {
    const section = PAGE.slice(PAGE.indexOf('creative-video-retention'))

    expect(section).toContain('<ConversionFunnelChart')
  })
})
