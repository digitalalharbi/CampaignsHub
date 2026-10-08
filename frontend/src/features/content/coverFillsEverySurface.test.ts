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
