import { describe, expect, it } from 'vitest'

/**
 * CLIENT-DIAGNOSTIC-SEPARATION-001 — the operator's vocabulary never reaches the client's page.
 *
 * Observed on the owner's own live link: «مؤشرات لا ترسلها المنصات المرتبطة … آخر مزامنة:
 * 2026-08-18T23:59». A client opened a report about their campaigns and was shown our sync clock.
 *
 * ## The distinction this guard is built on
 *
 * A client-facing statement about MISSING DATA is not forbidden and must not be removed — «these
 * figures do not include Snapchat» is a fact about their campaign, and hiding it would let a total
 * silently omit a platform. What is forbidden is the same fact told in OUR terms: «last synced»,
 * «awaiting credentials», «the connector failed». The first is about their money; the second is
 * about our plumbing, and a client can act on neither the timestamp nor the state name.
 *
 * So the scan is for the vocabulary, not for the subject. A surface may say a platform is not
 * included; it may not say when we last read it.
 *
 * ## Why source and not rendered output
 *
 * A rendering test only covers the states somebody thought to render. This defect lived in a strip
 * that only appears when a platform is unconnected — the state nobody builds a fixture for.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.tsx', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** The surfaces a client can open without a session, or that are addressed to one. */
const CLIENT_SURFACES = [
  'src/features/reports/LiveSharedReport.tsx',
  'src/features/reports/PublicReport.tsx',
  'src/features/reports/SharedCreativeSection.tsx',
  'src/features/reports/SharedAttributionSection.tsx',
  'src/features/reports/LiveDetailTables.tsx',
  'src/features/reports/ReportAdsSection.tsx',
  'src/features/reports/ReportAdDetail.tsx',
]

/**
 * The operator's words. Each is a thing a client cannot act on, in a place they cannot ask about it.
 *
 * Arabic and English both, because the product ships both and a leak in one language is a leak.
 */
const OPERATOR_VOCABULARY = [
  'آخر مزامنة',
  'تعذّرت المزامنة',
  'لم تتم مزامنة',
  'آخر تحديث من المصدر',
  'بيانات الاعتماد',
  'المنصات المرتبطة',
  'Awaiting credentials',
  'Last synced',
  'last sync',
  'Sync failed',
  'connector',
  'webhook',
  'API token',
]

/**
 * Comments are stripped.
 *
 * These files have to explain why they no longer print a sync clock, and a scan that reads prose
 * either fails on its own rationale or drives the rationale out of the code. The second is worse.
 */
function withoutComments(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1')
}

const sourceOf = (path: string): string => {
  const entry = Object.entries(TREE).find(([key]) => key.replace(/^\/+/, '') === path)

  if (entry === undefined) throw new Error(`${path} is not in the tree — this guard is watching a file that moved.`)

  return entry[1]
}

describe('a client-facing surface', () => {
  it('is watching files that still exist', () => {
    for (const path of CLIENT_SURFACES) {
      expect(() => sourceOf(path), `${path} moved`).not.toThrow()
    }
  })

  for (const path of CLIENT_SURFACES) {
    it(`«${path.split('/').pop()}» speaks no operator vocabulary`, () => {
      const code = withoutComments(sourceOf(path))
      const found = OPERATOR_VOCABULARY.filter((word) => code.includes(word))

      expect(
        found,
        'a client cannot act on our sync clock or our credential state — say what is missing from THEIR figures instead',
      ).toEqual([])
    })
  }

  /**
   * **One question, asked once.**
   *
   * `PrintReport` decided twice, in one file, whether its reader is client-facing — and got two
   * different answers. The PDF's title withheld `rid`, `checksum` and `data_version` from the
   * EXECUTIVE file, treating it as a client document; the methodology page printed all three, plus
   * `daily_metrics` and the attribution window, because it asked only whether the audience was
   * literally `client`. One document, both statements.
   *
   * A predicate that exists twice will eventually disagree with itself, so this pins the direction:
   * the audience question is `isClientAudience`, and a second copy of it fails here.
   */
  it('asks the audience question in one place', () => {
    const copies = Object.entries(TREE)
      .filter(([path]) => !/\.test\.tsx?$/.test(path))
      .filter(([path]) => !path.endsWith('/InteractiveReport.tsx'))
      .filter(([, source]) => /audience\s*[=!]==\s*'(client|executive)'/.test(withoutComments(source)))
      .map(([path]) => path)

    expect(
      copies,
      'use `isClientAudience` from InteractiveReport — a second copy of this predicate is how one\n'
      + 'document came to say «client file» in its metadata and print a checksum on its page:\n  '
      + copies.join('\n  '),
    ).toEqual([])
  })
})
