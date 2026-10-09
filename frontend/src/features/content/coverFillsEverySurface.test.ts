import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { mediaFit, mediaFitClass } from './adPreview'

/**
 * CONTENT-THUMB-FILL-001 — the owner has reported a letterboxed cover twice.
 *
 * The first report produced the `cover` surface, and the library grid was fixed: 225×225 frames,
 * `object-cover`, filled. The second report was about everywhere ELSE. Five surfaces draw a creative
 * into a square of 36 to 64 pixels through the `thumb` surface, and `thumb` filled only where the
 * asset's shape was known AND matched — so a horizontal still sat in a square with grey bands down
 * two sides, and the two callers that pass no shape at all contained every asset they ever drew.
 *
 * The rule it came from, «a story is contained, never covered», is about a surface somebody READS.
 * At 36 pixels nobody reads a call to action; a thumbnail is what you recognise a row by. The whole
 * asset stays one click away in the viewer, which still contains — and the test below holds that
 * line, because this change would be wrong if it ever reached the viewer.
 */
describe('a cover fills, and a surface that is read does not', () => {
  it.each(['cover', 'thumb'] as const)('fills a %s whatever the asset is', (surface) => {
    for (const asset of ['square', 'vertical', 'horizontal', null, undefined] as const) {
      for (const stage of ['square', 'vertical', 'horizontal', null] as const) {
        expect(mediaFit(asset, stage, surface), `${asset} in ${stage}`).toBe('cover')
        expect(mediaFitClass(asset, stage, surface)).toBe('object-cover')
      }
    }
  })

  it.each(['stage', 'viewer'] as const)('never crops a %s, which is the surface being read', (surface) => {
    for (const asset of ['square', 'vertical', 'horizontal', null, undefined] as const) {
      for (const stage of ['square', 'vertical', 'horizontal', null] as const) {
        expect(mediaFit(asset, stage, surface), `${asset} in ${stage}`).toBe('contain')
      }
    }
  })
})

/**
 * The surfaces themselves, not just the rule.
 *
 * A rule that returns «cover» helps nobody if a surface asks it the wrong question, and that is
 * exactly how the second report happened: `mediaFit` was right about `cover`, and five call sites
 * were still asking for `thumb`. This reads the call sites.
 */
describe('every small creative frame asks for a filling surface', () => {
  const SURFACES = [
    'src/features/content/CreativeCarousel.tsx',
    'src/features/content/CreativeGroupsPage.tsx',
    'src/features/content/CreativePulseSection.tsx',
    'src/features/campaigns/CampaignStructureTab.tsx',
    'src/features/campaigns/CampaignComparison.tsx',
  ]

  it.each(SURFACES)('%s draws its thumbnail through a filling surface', (file) => {
    const source = readFileSync(join(process.cwd(), file), 'utf8')
    /*
      Balanced, because the arguments contain calls of their own.

      `[^)]*` stopped at the first `)` — which inside
      `mediaFitClass(preview.aspect ?? assetAspect(w, h, r), null, 'thumb')` is the one closing
      `assetAspect`, so the surface argument was never in the captured text and a correct call read
      as a violation. The test was wrong about the source, which is the failure mode a source-reading
      test has to be most careful about.
    */
    const calls: string[] = []

    for (let i = source.indexOf('mediaFitClass('); i !== -1; i = source.indexOf('mediaFitClass(', i + 1)) {
      let depth = 0

      for (let j = i + 'mediaFitClass'.length; j < source.length; j++) {
        if (source[j] === '(') depth += 1
        if (source[j] === ')') {
          depth -= 1
          if (depth === 0) {
            calls.push(source.slice(i + 'mediaFitClass('.length, j))
            break
          }
        }
      }
    }

    expect(calls.length, 'this surface no longer calls mediaFitClass — update the list').toBeGreaterThan(0)

    for (const call of calls) {
      expect(
        /'(thumb|cover)'/.test(call),
        `a small frame asked for a surface that contains: mediaFitClass(${call.trim()})`,
      ).toBe(true)
    }
  })
})

/**
 * The OTHER component that draws a creative, and the one the second report was actually about.
 *
 * `mediaFitClass` is not the only door. `AdPoster` derives its own fit from the asset's shape unless
 * the caller states one — which letterboxed every creative whose shape was not the tile's, and all
 * of the ones whose shape the platform never sent. The library grid does not use it; the reports,
 * the campaign grid and the analytics rows do, which is exactly the set the owner named: «في
 * التقارير لم يتم تحديث الغلاف … أبعاد الغلاف غير ملائمة».
 *
 * So the surfaces are read from source, and they are split into the two kinds on purpose. A guard
 * that only checked the filling half would go green on the day somebody made the viewer fill too,
 * and that is the line this change would be wrong to cross.
 */
describe('every AdPoster surface states its own fit, and the right one', () => {
  /** Cards and thumbnails: what you recognise a row by. They fill. */
  const FILLING: Array<[string, string]> = [
    ['src/features/reports/live/LiveContent.tsx', 'live-content-poster'],
    ['src/features/reports/ReportAdsSection.tsx', 'testid={testidPrefix}'],
    ['src/features/reports/ReportCreativeRoster.tsx', 'report-roster-poster'],
    ['src/features/campaigns/CampaignCommandCenter.tsx', 'ad-poster-'],
    ['src/features/analytics/AnalyticsPage.tsx', 'analytics-creative-poster'],
    ['src/features/analytics/AnalyticsPage.tsx', 'ad-thumb-'],
  ]

  /** Surfaces somebody opened to LOOK at the ad. They must never crop. */
  const READING: Array<[string, string]> = [
    ['src/features/reports/ReportAdDetail.tsx', 'report-ad-detail-poster'],
    ['src/features/campaigns/CampaignCommandCenter.tsx', 'ad-preview-panel-poster'],
  ]

  /** The `<AdPoster …/>` element carrying this marker, as source. */
  function element(file: string, marker: string): string {
    const source = readFileSync(join(process.cwd(), file), 'utf8')
    let found: string | null = null

    for (let i = source.indexOf('<AdPoster'); i !== -1; i = source.indexOf('<AdPoster', i + 1)) {
      const end = source.indexOf('/>', i)
      const text = source.slice(i, end === -1 ? i + 600 : end)

      if (text.includes(marker)) {
        found = text
        break
      }
    }

    expect(found, `no <AdPoster> carrying ${marker} in ${file} — update the list`).not.toBeNull()

    return found as string
  }

  it.each(FILLING)('%s (%s) fills its frame', (file, marker) => {
    expect(
      element(file, marker),
      `a card or thumbnail is letterboxed again: ${file} (${marker})`,
    ).toContain('fit="cover"')
  })

  it.each(READING)('%s (%s) is never cropped', (file, marker) => {
    expect(
      element(file, marker),
      `a surface somebody opened to read was made to crop: ${file} (${marker})`,
    ).not.toContain('fit="cover"')
  })
})

/**
 * The printed page draws its own `<img>`, and it has to fill too.
 *
 * `PrintDocument` cannot use `AdPoster` — the PDF is produced by Chromium from static markup, and
 * that component renders states, hover and a dialog — so it styles its thumbnail in the document's
 * own stylesheet. It was already `object-fit: cover`, which is why the owner never reported the PDF;
 * this pins it, because the default for an `<img>` given both a width and a height is `fill`, and a
 * STRETCHED creative in a document a client keeps is worse than a letterboxed one.
 */
describe('the printed thumbnail fills as well', () => {
  it('sets object-fit: cover on the document ad thumbnail', () => {
    const source = readFileSync(join(process.cwd(), 'src/features/reports/PrintDocument.tsx'), 'utf8')
    const rule = source.split('\n').find((line) => line.includes('.doc-ad-thumb img'))

    expect(rule, 'the printed thumbnail no longer has a rule of its own — update this guard').toBeDefined()
    expect(rule, 'the printed creative is stretched or letterboxed').toContain('object-fit: cover')
  })
})
