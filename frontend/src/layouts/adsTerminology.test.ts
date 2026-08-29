import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * ADS-TERMINOLOGY-001 — the advertising entity has ONE user-facing name.
 *
 * «الإعلانات» in Arabic, «Ads» in English. The product had three names for it and showed all of
 * them: navigation said «المحتوى / Content», the library called itself «مكتبة المحتويات / Creative
 * library», the report scope said «المحتويات», and the paid-media brief offered «المحتويات /
 * الإبداعات» in one field label. Every one of those is defensible on its own and the set is not: a
 * customer reading «المحتوى» in the sidebar, «المحتويات» in a report and «الإبداعات» in a form has
 * to work out that they are the same thing, and some of them will decide they are not.
 *
 * ## What this deliberately does NOT touch
 *
 * The `Creative` model, `external_creatives`, `/api/v1/creatives`, the `creative` route param, the
 * `content` entitlement key and every test id built from them. A creative is carried by many ads —
 * that relation is real, the schema says so, and renaming it would be a destructive change dressed
 * as a copy fix. This is about the words on screen.
 *
 * Two places also keep a different word ON PURPOSE, and the guard allows exactly them:
 *
 *   * `CreativePulseSection`'s drill-down calls its last rung «المادة الإعلانية / Ad asset», because
 *     the rung ABOVE it is the ad and «… › الإعلان › الإعلان» is two links, one word, two places.
 *   * The influencer and UGC surfaces say «المحتوى» meaning content made by a creator, which is a
 *     different domain and not an advertising entity at all.
 */

const ROOT = process.cwd()

/** The words that must not name an advertising entity in navigation. */
const COMPETING = ['المحتوى', 'المحتويات', 'الإبداعات']

type Leaf = { to: string; ar: string; en: string }

function leaves(file: string): Leaf[] {
  const source = readFileSync(join(ROOT, 'src/layouts', file), 'utf8')

  return [...source.matchAll(/\{\s*to:\s*'([^']+)',\s*ar:\s*'([^']+)',\s*en:\s*'([^']+)'/g)].map(
    ([, to, ar, en]) => ({ to: to!, ar: ar!, en: en! }),
  )
}

describe('ADS-TERMINOLOGY-001 — navigation', () => {
  it.each(['appNav.ts', 'agencyNav.ts'])('%s names the ads destination «الإعلانات» / «Ads»', (file) => {
    const ads = leaves(file).find((l) => l.to.endsWith('/content'))

    expect(ads, `${file} has no ads destination — the parser found nothing`).toBeDefined()
    expect(ads).toMatchObject({ ar: 'الإعلانات', en: 'Ads' })
  })

  it.each(['appNav.ts', 'agencyNav.ts'])('%s uses none of the competing names anywhere', (file) => {
    const offenders = leaves(file).filter((l) => COMPETING.some((w) => l.ar.includes(w)) || /Creative/i.test(l.en))

    expect(offenders).toEqual([])
  })

  /** The parser reads the navigation rather than an empty list it can always agree with. */
  it('reads real navigation entries', () => {
    expect(leaves('appNav.ts').length).toBeGreaterThan(6)
    expect(leaves('agencyNav.ts').length).toBeGreaterThan(4)
  })
})

/**
 * And the surfaces that carry the entity keep the name in their own titles.
 *
 * A `title:` in a COPY block is the heading a reader sees at the top of a page, which is the second
 * place after navigation where a product states what something is called.
 */
describe('ADS-TERMINOLOGY-001 — page titles on the ads surfaces', () => {
  const SURFACES = [
    'src/features/content/CreativesPage.tsx',
    'src/features/content/CreativeDetailPage.tsx',
    'src/features/content/CreativeGroupsPage.tsx',
    'src/features/content/CreativePulseSection.tsx',
    'src/features/reports/SharedCreativeSection.tsx',
    'src/features/reports/ReportScopePicker.tsx',
  ]

  it.each(SURFACES)('%s states no competing name in a title', (path) => {
    /*
     * The keys that NAME the entity, not every string on the page. `CreativeDetailPage` has no
     * `title` of its own — it is opened from the library and titled by the creative's name — so a
     * scan for `title` alone silently found nothing there and passed.
     */
    const titles = [...readFileSync(join(ROOT, path), 'utf8').matchAll(/\b(?:title|heading|library|details|kind|members):\s*'([^']+)'/g)]
      .map(([, text]) => text!)

    expect(titles.length, 'no titles were found — the parser is agreeing with itself').toBeGreaterThan(0)
    expect(titles.filter((t) => COMPETING.some((w) => t.includes(w)) || /Creative/i.test(t))).toEqual([])
  })
})

/** Nothing above can pass by accident: the words really are still in the tree, in their own domain. */
describe('the influencer surfaces keep their own word', () => {
  it('still says «المحتوى» where a creator made it', () => {
    const source = readFileSync(join(ROOT, 'src/features/auth/AuthPanel.tsx'), 'utf8')

    expect(source).toContain('المحتوى')
  })
})

/** A directory walk exists so the two describes above cannot silently point at deleted files. */
describe('the files this guard names still exist', () => {
  it('finds every layout it reads', () => {
    const present = readdirSync(join(ROOT, 'src/layouts')).filter((f) => statSync(join(ROOT, 'src/layouts', f)).isFile())

    expect(present).toEqual(expect.arrayContaining(['appNav.ts', 'agencyNav.ts']))
  })
})
