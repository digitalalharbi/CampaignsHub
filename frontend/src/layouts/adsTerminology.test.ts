import { describe, expect, it } from 'vitest'

/**
 * CONTENT-TERMINOLOGY-001 · ADS-TERMINOLOGY-001 — two things, two names, and neither borrows the
 * other's.
 *
 * ## What this file used to enforce, and why it changed
 *
 * It insisted on ONE name — «الإعلانات / Ads» — for everything creative, because the product had
 * three and showed all of them: the sidebar said «المحتوى», the library «مكتبة المحتويات», a report
 * «المحتويات», a form «الإبداعات». Collapsing them to one word fixed the confusion and introduced a
 * different one, which the owner named directly: the library is not a list of ads. It is the
 * pictures and films themselves, one of which is carried by several ads across several platforms —
 * a relation the schema has always modelled and the vocabulary had stopped expressing.
 *
 * So there are two names now, and the split is the product's own:
 *
 *   * **«المحتويات» / «Content»** — the media library: the asset, its preview, its own performance.
 *   * **«الإعلانات» / «Ads»** — the advertising entity: what a campaign bought, ranked, reported on.
 *
 * A report's «الإعلانات الأعلى أداءً» is ads, correctly, and it is not the library. The library's
 * «مكتبة المحتويات» is content, correctly, and it is not a campaign.
 *
 * ## What this deliberately does NOT touch
 *
 * The `Creative` model, `external_creatives`, `/api/v1/creatives`, the `creative` route param, the
 * `content` entitlement key and every test id built from them. Renaming those would be a
 * destructive change dressed as a copy fix. This is about the words on screen.
 *
 * The influencer and UGC surfaces keep «المحتوى» meaning content made by a creator — a different
 * domain, and the reason this guard names strings rather than scanning blindly.
 */

/*
 * Read through Vite rather than `node:fs`: this suite's tsconfig carries no Node types, and adding
 * them to type a few `readFileSync` calls would widen the app's type surface to buy nothing.
 */
const TREE: Record<string, string> = import.meta.glob('/src/**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** The file's source, or a failure naming it — a guard that reads nothing must not pass. */
function read(path: string): string {
  const source = TREE['/' + path]
  if (source === undefined) throw new Error(`${path} is not in the source tree — this guard reads a file that moved`)
  return source
}

/** The word the CONTENT surfaces must not use: it names the advertising entity, not the asset. */
const NOT_CONTENT = ['الإعلانات', 'الإعلان ', 'الإبداعات']

type Leaf = { to: string; ar: string; en: string }

function leaves(file: string): Leaf[] {
  const source = read(`src/layouts/${file}`)

  return [...source.matchAll(/\{\s*to:\s*'([^']+)',\s*ar:\s*'([^']+)',\s*en:\s*'([^']+)'/g)].map(
    ([, to, ar, en]) => ({ to: to!, ar: ar!, en: en! }),
  )
}

describe('CONTENT-TERMINOLOGY-001 — navigation', () => {
  it.each(['appNav.ts', 'agencyNav.ts'])('%s names the library «المحتويات» / «Content»', (file) => {
    const library = leaves(file).find((l) => l.to.endsWith('/content'))

    expect(library, `${file} has no library destination — the parser found nothing`).toBeDefined()
    expect(library).toMatchObject({ ar: 'المحتويات', en: 'Content' })
  })

  /** «الإبداعات» was never one of the two names and is still not. */
  it.each(['appNav.ts', 'agencyNav.ts'])('%s never says «الإبداعات» or «Creative»', (file) => {
    const offenders = leaves(file).filter((l) => l.ar.includes('الإبداعات') || /Creative/i.test(l.en))

    expect(offenders).toEqual([])
  })

  /** The parser reads the navigation rather than an empty list it can always agree with. */
  it('reads real navigation entries', () => {
    expect(leaves('appNav.ts').length).toBeGreaterThan(6)
    expect(leaves('agencyNav.ts').length).toBeGreaterThan(4)
  })
})

/**
 * The library's own headings say what the library holds.
 *
 * A `title:` in a COPY block is the heading at the top of a page, which is the second place after
 * navigation where a product states what something is called — and the place a customer reads when
 * the rail is collapsed.
 */
describe('CONTENT-TERMINOLOGY-001 — the library surfaces', () => {
  const SURFACES = [
    'src/features/content/CreativesPage.tsx',
    'src/features/content/CreativeGroupsPage.tsx',
    'src/features/content/CreativePulseSection.tsx',
  ]

  it.each(SURFACES)('%s names its subject as content, not as an ad', (path) => {
    const titles = [...read(path).matchAll(/\b(?:title|heading|library|details|kind|members):\s*'([^']+)'/g)]
      .map(([, text]) => text!)

    expect(titles.length, 'no titles were found — the parser is agreeing with itself').toBeGreaterThan(0)
    expect(titles.filter((t) => NOT_CONTENT.some((w) => t.includes(w)) || /\bads?\b/i.test(t))).toEqual([])
  })

  it('states the library heading in both languages', () => {
    const source = read('src/features/content/CreativesPage.tsx')

    expect(source).toContain("title: 'مكتبة المحتويات'")
    expect(source).toContain("title: 'Content library'")
  })
})

/**
 * And the ADVERTISING surfaces keep the other name.
 *
 * This half is what stops the correction becoming the original defect with the words swapped. A
 * report ranking what a campaign bought is ranking ADS: «الإعلانات الأعلى أداءً» is right there and
 * «المحتويات الأعلى أداءً» would be wrong, because the thing being ranked is the ad and its spend,
 * not the picture.
 */
describe('ADS-TERMINOLOGY-001 — the advertising surfaces keep «الإعلانات»', () => {
  const SAYS_ADS: [string, string[]][] = [
    /*
     * «الأضعف» moved into `ReportAdsSection` — CLIENT-REPORT-ENTITY-BOUNDARY-001.
     *
     * The deck had its own «أضعف الإعلانات» grid, and what it ranked were CAMPAIGNS: `top_creatives`
     * and `worst_creatives` are campaign rows, as `creative_level` said all along. Both slides now
     * render the real ad section, which states the strongest and the weakest ad in its own reading —
     * so the vocabulary is still asserted, at the one place that now owns it.
     */
    ['src/features/reports/ReportAdsSection.tsx', ['الإعلانات الأعلى أداءً', 'Top performing ads', 'الأضعف ']],
    ['src/features/reports/InteractiveReport.tsx', ['أفضل الإعلانات']],
    /*
     * The report label moved to `reportProduct.ts` with REPORT-PRODUCT-MODEL-001 — it is chosen by
     * mode AND form now and lives with the other three. The guard follows the copy rather than the
     * file it used to sit in; pinning it to `PublicReport.tsx` would be satisfied by a page that no
     * longer says anything at all.
     */
    ['src/features/reports/reportProduct.ts', ['كل المنصات والحملات والإعلانات']],
    ['src/features/campaigns/overview/UnifiedCampaignOverview.tsx', ["topCreatives: 'أفضل الإعلانات'", "topCreatives: 'Best ads'"]],
  ]

  for (const [path, expected] of SAYS_ADS) {
    it(`${path} names the advertising entity «الإعلانات»`, () => {
      const source = read(path)

      for (const phrase of expected) expect(source).toContain(phrase)
    })
  }

  /** The shared label table answers the library's keys with the library's word. */
  it('the label table names the library, not the ads', () => {
    const source = read('src/lib/i18n.ts')

    expect(source).toContain("content: 'المحتويات'")
    expect(source).toContain("tab_creatives: 'المحتويات'")
    expect(source).toContain("content: 'Content'")
    expect(source).toContain("tab_creatives: 'Content'")
  })
})

/** Nothing above can pass by accident: the creator-content word really is still in the tree. */
describe('the influencer surfaces keep their own word', () => {
  it('still says «المحتوى» where a creator made it', () => {
    expect(read('src/features/auth/AuthPanel.tsx')).toContain('المحتوى')
  })
})

/** A directory walk exists so the describes above cannot silently point at deleted files. */
describe('the files this guard names still exist', () => {
  it('finds every layout it reads', () => {
    const present = Object.keys(TREE)
      .filter((path) => path.startsWith('/src/layouts/'))
      .map((path) => path.slice('/src/layouts/'.length))

    expect(present).toEqual(expect.arrayContaining(['appNav.ts', 'agencyNav.ts']))
  })
})
