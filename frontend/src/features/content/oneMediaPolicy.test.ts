import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * CONTENT-PREVIEW-FIT-001 §14 — one media policy, read by every surface that draws an ad.
 *
 * ## Owner regression 9
 *
 * «Report and Content surface use same media absence policy… Do not solve only one renderer.» The
 * list of renderers is the problem: the library grid, the table, the viewer, the carousel, the
 * ranking cards, Content Analytics, the campaign centre, the client's deck, the live report and the
 * printed document all draw the same creative, and each one that resolves its own picture is a
 * surface free to disagree about what that creative looks like and why it is missing.
 *
 * ## What this holds, and why it is a source scan
 *
 * Two rules, both about the DECISION rather than the pixels:
 *
 *  - nothing picks its own `thumbnail_url ?? image_url` — that chain lives in `readPreview`, which
 *    already knows about a video whose poster is all that arrived, a collection whose hero is a
 *    film, and a catalog ad that is missing nothing;
 *  - nothing hard-codes `object-cover` on a creative — covering is a crop, and the one rule allowed
 *    to authorise one is `mediaFit`.
 *
 * A render test cannot ask this. It asks what ONE surface drew, and the failure being prevented is a
 * NEW surface arriving with its own copy of the rules — which is how `has_preview`, three separate
 * poster chains and two different crop rules all existed at once before AD-PREVIEW-001.
 */
const ROOTS = ['src/features/content', 'src/features/reports', 'src/features/analytics', 'src/features/campaigns']

/** Already the canonical resolvers, or a test about them. */
const OWNS_THE_POLICY = [
  'adPreview.ts', 'AdPoster.tsx', 'VideoPoster.tsx', 'PosterImage.tsx',
  /*
   * `previewPresence` asks a different question and is exempt for that reason, not for convenience.
   * It answers «does this RESULT SET have any creative file at all», which decides whether the grid
   * reserves a media column — so it counts the three urls rather than choosing between them, and a
   * film with no still is an asset to it where it is an absence to the resolver.
   */
  'previewPresence.ts',
]

/**
 * Comments are not code, and this file is full of them.
 *
 * The first version of this scan read raw source and reported five offenders, three of which were
 * the paragraphs explaining why the chain had been removed. A guard that fires on its own
 * documentation teaches people to stop writing it.
 */
function code(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .split('\n')
    .filter((line) => !/^\s*(\/\/|\*)/.test(line))
    .join('\n')
}

function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name)

    if (entry.isDirectory()) return sources(path)
    if (!/\.tsx?$/.test(entry.name)) return []
    if (/\.test\.tsx?$/.test(entry.name)) return []
    if (OWNS_THE_POLICY.includes(entry.name)) return []

    return [path]
  })
}

const files = ROOTS.flatMap(sources)

describe('one media policy', () => {
  it('is read by every surface, not re-derived', () => {
    const offenders = files.filter((path) => {
      /* The poster chain, spelled out by hand. */
      return /thumbnail_url\s*\?\?\s*[\w.]*image_url|image_url\s*\?\?\s*[\w.]*thumbnail_url/.test(
        code(readFileSync(path, 'utf8')),
      )
    })

    expect(
      offenders,
      `these surfaces resolve their own poster instead of asking readPreview:\n  ${offenders.join('\n  ')}`,
    ).toEqual([])
  })

  it('never authorises a crop by hand', () => {
    const offenders = files.filter((path) => {
      /*
       * `object-cover` reached through `mediaFitClass` is the rule deciding, which is the point.
       * What this looks for is the literal utility sitting on an element's className.
       */
      return code(readFileSync(path, 'utf8'))
        .split('\n')
        .some((line) => /object-cover/.test(line) && !/mediaFitClass|mediaFit\(/.test(line))
    })

    expect(
      offenders,
      `these surfaces crop a creative without asking mediaFit:\n  ${offenders.join('\n  ')}`,
    ).toEqual([])
  })
})
