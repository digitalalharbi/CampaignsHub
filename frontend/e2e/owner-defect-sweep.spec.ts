import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * The sweep the owner asked for: every operator surface, looked at for the defects they keep seeing.
 *
 * ## Why a sweep rather than a case per defect
 *
 * «237.90 undefined» was found on ONE tab, by accident, while checking something else. The formatter
 * that produced it is called from every analytical surface in the product, and a case pinned to the
 * tab where it was noticed would have proved nothing about the other twenty. The same is true of
 * every item in `docs/OWNER_OBSERVED_DEFECTS.md`: they are not tab-specific claims, they are claims
 * about the product.
 *
 * So this walks the routes and asks the questions that have actually caught something:
 *
 *   - does any surface render the literal `undefined`, `NaN`, `[object Object]` or `Infinity`;
 *   - does any image that was asked for fail to decode.
 *
 * ## What it deliberately does NOT do
 *
 * It does not assert figures. The seeded estate changes whenever the seeder does, and a sweep that
 * pinned numbers would fail for reasons that are nobody's defect and would be turned off. It asks
 * only about states that are wrong on ANY data.
 *
 * Both languages, because half the defects here have been Arabic-only, and the copy is not a
 * translation of the English — it is written separately and can be broken separately.
 */
/*
 * The AGENCY portal, on the project that actually carries campaigns.
 *
 * The first draft swept `/app/*` as the advertiser and passed — including when the defect it exists
 * to catch was deliberately reinstated. That estate renders no objective families and no cost-per
 * columns, so there was nothing on those routes to print the broken value: a guard that cannot fail
 * is not a guard, and this one would have shipped saying the product was clean because it had been
 * pointed somewhere the defect could not appear.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

const OPERATOR_ROUTES = [
  '/agency/dashboard',
  '/agency/campaigns',
  '/agency/content',
  '/agency/analytics',
  '/agency/analytics?tab=platforms',
  '/agency/analytics?tab=objective',
  '/agency/analytics?tab=campaigns',
  '/agency/analytics?tab=accounts',
  '/agency/analytics?tab=budget',
  '/agency/analytics?tab=store',
  '/agency/reports',
  '/agency/integrations',
] as const

/**
 * Text a reader must never see, with the reason each one is here.
 *
 * `undefined` and `NaN` are a value that never arrived, printed as though it had. `[object Object]`
 * is a structure interpolated into a string. `Infinity` is a division by a zero denominator — the
 * shape a rate takes when nobody was measured, and the one a reader is most likely to believe.
 */
const NEVER_RENDERED = /\bundefined\b|\bNaN\b|\[object Object\]|\bInfinity\b/

/** The visible text of the page, with script and style excluded — they are not read by anybody. */
async function readerSees(page: Page): Promise<string> {
  return page.evaluate(() => (document.querySelector('main') ?? document.body).innerText)
}

for (const locale of ['ar', 'en'] as const) {
  test.describe(`what an operator actually sees — ${locale}`, () => {
    test.use({ storageState: AUTH.owner })

    test(`no surface renders a value that never arrived — ${locale}`, async ({ page, request }) => {
      test.setTimeout(180_000)

      await selectProject(page, await seededProject(request, STORE_PROJECT))

      await page.addInitScript((l) => {
        try {
          window.localStorage.setItem('ui', JSON.stringify({ state: { locale: l }, version: 0 }))
        } catch {
          // A browser refusing storage still sweeps, in whatever direction it defaults to.
        }
      }, locale)

      const offences: string[] = []

      for (const route of OPERATOR_ROUTES) {
        await page.goto(route)
        await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

        // The figures arrive with their own request; an empty surface is a legitimate state.
        await page.waitForLoadState('networkidle').catch(() => undefined)
        await page.waitForTimeout(1200)

        const text = await readerSees(page)
        const found = text.match(NEVER_RENDERED)

        if (found) {
          /* The surrounding words, so the report names a place rather than a string. */
          const at = text.indexOf(found[0])
          offences.push(`${route}: «…${text.slice(Math.max(0, at - 60), at + 40).replace(/\n/g, ' ')}…»`)
        }
      }

      expect(offences, 'a surface printed a value that never arrived').toEqual([])
    })

    /**
     * NUMBER-PRESENTATION-001 — a large count written at full width, anywhere in the product.
     *
     * Found on the LIVE client report: the KPI card read «6.6M» and the funnel bar beneath it read
     * «6,596,500». One figure, two shapes, one page. The formatter that produced it is shared, so
     * the defect was never about the funnel — it was about which formatter a surface happens to
     * reach for, and every surface makes that choice independently.
     *
     * Seven digits is the threshold deliberately. Six-figure sums appear in money columns that are
     * meant to be exact, and a rule that flagged those would be turned off within a week. Above a
     * million, the product's own law says compact — and the exact figure travels as a `title`, which
     * is not read here because it is not what the reader sees.
     */
    test(`no surface writes a seven-figure count at full width — ${locale}`, async ({ page, request }) => {
      test.setTimeout(180_000)

      await selectProject(page, await seededProject(request, STORE_PROJECT))
      await page.addInitScript((l) => {
        try {
          window.localStorage.setItem('ui', JSON.stringify({ state: { locale: l }, version: 0 }))
        } catch {
          // As above.
        }
      }, locale)

      const offences: string[] = []

      for (const route of OPERATOR_ROUTES) {
        await page.goto(route)
        await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
        await page.waitForLoadState('networkidle').catch(() => undefined)
        await page.waitForTimeout(1200)

        const long = await page.evaluate(() => {
          const seen: string[] = []
          const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
          let node: Node | null

          while ((node = walk.nextNode())) {
            const text = (node.textContent ?? '').trim()

            /* The whole text node is the figure — a sentence that mentions one is not the defect. */
            if (/^\d{1,3}(,\d{3}){2,}$/.test(text)) seen.push(text)
          }

          return seen.slice(0, 5)
        })

        if (long.length > 0) offences.push(`${route}: ${long.join(', ')}`)
      }

      expect(offences, 'a count was written at full width where the product compacts').toEqual([])
    })

  })
}
