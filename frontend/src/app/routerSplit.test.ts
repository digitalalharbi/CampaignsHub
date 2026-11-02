import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * PERF-RECOVERY-001 — one static page import pulls that page's whole graph back into the entry chunk,
 * and nothing about the app looks different when it happens: it just gets slower for everybody.
 */
describe('the router loads pages on demand', () => {
  const source = readFileSync(resolve(__dirname, 'router.tsx'), 'utf8')

  it('imports no page or shell statically', () => {
    const offenders = source
      .split('\n')
      .filter((line) => /^import \{[^}]*\} from '@\/(features|layouts)\//.test(line))
      // Four features declare their own route tables; those are held to the same rule below.
      .filter((line) => !/^import \{ \w+Routes \} from /.test(line))

    expect(offenders).toEqual([])
  })

  it('still routes a shared report through a lazy page', () => {
    expect(source).toMatch(/const PublicReport = lazyPage\(\(\) => import\('@\/features\/reports\/PublicReport'\), 'PublicReport'\)/)
  })

  /*
   * The four feature route tables are imported statically (they are arrays, not pages), so a page
   * imported statically INSIDE one of them is in the entry chunk — which is how `QuotesPage`, the
   * date field and the tax tables reached every visitor's first download.
   */
  it.each([
    'features/billing/billingRoutes.tsx',
    'features/subscriptions/subscriptionsRoutes.tsx',
    'features/messaging/messagingRoutes.tsx',
    'features/requestJourney/requestJourneyRoutes.tsx',
  ])('%s imports its pages through lazyPage only', (table) => {
    const text = readFileSync(resolve(__dirname, '..', table), 'utf8')
    const pageImports = text.split('\n').filter((line) => /^import \{ \w+Page \} from '\.\//.test(line))

    expect(pageImports).toEqual([])
    expect(text).toMatch(/lazyPage\(\(\) => import\('\.\/\w+Page'\), '\w+Page'\)/)
  })

  /*
   * The shared report page itself: the SNAPSHOT renderer and its two appendices load when the
   * report says it is a snapshot; a live link never downloads them.
   */
  it('keeps the snapshot halves of PublicReport behind import()', () => {
    const page = readFileSync(resolve(__dirname, '../features/reports/PublicReport.tsx'), 'utf8')

    for (const name of ['InteractiveReport', 'SharedAttributionSection', 'SharedCreativeSection']) {
      expect(page, name).toMatch(new RegExp(`const ${name} = lazyPage\\(\\(\\) => import\\('\\./${name}'\\), '${name}'\\)`))
      expect(page, `${name} static`).not.toMatch(new RegExp(`^import \\{ ${name} \\} from`, 'm'))
    }
    expect(page).toMatch(/^import \{ LiveSharedReport \} from '\.\/LiveSharedReport'/m)
  })
})
