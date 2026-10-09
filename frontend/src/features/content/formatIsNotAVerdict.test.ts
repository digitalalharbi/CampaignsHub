import { describe, expect, it } from 'vitest'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

/**
 * CONTENT-FORMAT-ROAS-REMOVED-001 — a format is a browsing dimension, never a performance verdict.
 *
 * The owner, with a screenshot: «يوجد خطأ فادح — كيف حققت الحملة أداء عائد إلى 5x بالمقابل
 * المحتويات العائد لها ضعيف جداً».
 *
 * The contradiction was structural. The removed block divided creative-grain REVENUE by
 * creative-grain SPEND: spend is attributed to every creative in full, revenue at that grain is
 * reported by the platform only sometimes. A fraction of the truth over all of it printed «0.02»
 * beside a campaign reading 5x, and a verdict on top of it — «الكولكشن أفضل في هذه الفترة» — asked
 * an operator to move budget on that number.
 *
 * This guards the REMOVAL, because a removal with no test is a deletion that comes back. It is a
 * source-level guard on purpose: there is no component left to render, so there is nothing to
 * assert against a DOM. What can be asserted is that no surface imports one again.
 */
const SRC = join(process.cwd(), 'src')

/** Every `.ts`/`.tsx` under `src`, so a reintroduction anywhere is caught rather than in a listed few. */
function sources(dir: string): string[] {
  const out: string[] = []

  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)

    if (entry.isDirectory()) {
      out.push(...sources(path))
    } else if (/\.tsx?$/.test(entry.name)) {
      out.push(path)
    }
  }

  return out
}

describe('the creative-format winner stays removed', () => {
  it('has no component and no verdict module to import', () => {
    expect(existsSync(join(SRC, 'features/content/CreativeFormatComparison.tsx'))).toBe(false)
    expect(existsSync(join(SRC, 'features/content/formatVerdict.ts'))).toBe(false)
    /* The second implementation, found only because the owner saw it still on screen. */
    expect(existsSync(join(SRC, 'features/analytics/ContentReading.tsx'))).toBe(false)
  })

  it('is imported by no surface', () => {
    const offenders = sources(SRC)
      .filter((file) => !file.endsWith('formatIsNotAVerdict.test.ts'))
      .filter((file) => /from '[^']*(CreativeFormatComparison|formatVerdict|ContentReading)'/.test(readFileSync(file, 'utf8')))
      .map((file) => file.slice(SRC.length + 1))

    expect(offenders, 'a surface imports the removed format winner again').toEqual([])
  })

  /*
    The verdict SENTENCES, which are the part that actually told somebody what to do.

    Checked as copy rather than as a symbol: the module could come back under another name and the
    defect would be identical. These are the exact strings the removed verdict composed.
  */
  it('composes no «which format is better» sentence anywhere', () => {
    /*
      Widened after the first removal shipped incomplete.

      The owner saw the verdict still standing on the dashboard, because `ContentReading` made the
      same claim in different words — «الصور يحقق العائد على الإنفاق أفضل من الفيديو في هذه الفترة»
      — and the first pass searched for component NAMES (`CreativeFormatComparison`, `formatVerdict`)
      rather than for the claim. A guard that only knows the one implementation it was written
      against cannot stop the second one.

      So these are the sentence shapes those two verdicts actually composed — and no wider than
      that. A first attempt added «أفضل من» and «الأعلى أفضل» on their own and flagged five innocent
      files: `PathAnalysis`, `CampaignCommandCenter`, `CreativePulseSection` and the report, which
      compare PATHS, CAMPAIGNS and individual CREATIVES. The constitution forbids declaring a FORMAT
      better (§3) and explicitly keeps «best individual creatives where evidence is trustworthy»
      (§4). A guard that cannot tell those apart would delete the product's legitimate comparisons.
    */
    const CLAIMS = [
      'أفضل في هذه الفترة',
      'performs better in this period',
      /* `ContentReading`'s own wording, which the first pass of the removal missed entirely. */
      'is reaching a better',
    ]

    /*
      Comments are stripped first, and that correction matters.

      The first version of this scanned raw source and failed on three files — all of them
      DOCBLOCKS explaining why the verdict was removed, including this file's own. A guard that
      forbids writing down why something is gone would push the reasoning out of the codebase,
      which is the opposite of what it should do. What must not exist is the sentence as COPY.
    */
    const withoutComments = (source: string): string =>
      source.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/^\s*\/\/.*$/gm, ' ')

    const offenders = sources(SRC)
      .filter((file) => !file.endsWith('formatIsNotAVerdict.test.ts'))
      .filter((file) => {
        const code = withoutComments(readFileSync(file, 'utf8'))

        return CLAIMS.some((claim) => code.includes(claim))
      })
      .map((file) => file.slice(SRC.length + 1))

    expect(offenders, 'a surface declares one format better than another').toEqual([])
  })
})

/**
 * What the owner explicitly kept — constitution §4.
 *
 * «Do NOT remove useful factual capabilities.» Format stays as a filter, a badge and a count, and
 * the spend distribution by type stays because it divides nothing. A removal that took those with
 * it would be over-correction, so this holds them in place.
 */
describe('the factual capabilities about content type remain', () => {
  it('keeps the type vocabulary every badge and filter reads', () => {
    expect(existsSync(join(SRC, 'features/content/creativeKind.ts'))).toBe(true)
  })

  it('keeps the spend distribution by type, which divides nothing', () => {
    expect(existsSync(join(SRC, 'features/content/formatMix.ts'))).toBe(true)
  })
})
