import { expect, test } from '@playwright/test'
import { AUTH, selectProject, switchToEnglish } from './helpers'

/**
 * PRODUCT-VISUAL-001 §44 — the walk, as a test instead of as a promise.
 *
 * ## What this holds
 *
 * A reader crossing the authenticated rail must meet ONE product, not a set of pages that each
 * invented their own header. Three things are checked on every surface a rail link reaches, in both
 * writing directions and at both widths that matter:
 *
 *  - exactly one shared head, so «where am I» is answered in the same place every time;
 *  - no sideways scroll, because content reachable only by dragging is content a phone user will
 *    not find;
 *  - no `undefined`, `NaN` or `[object Object]` reaching a reader, which is what a page prints when
 *    it formats a figure that never arrived.
 *
 * ## Why one spec and not a per-page assertion
 *
 * The claim is about the product rather than about any page in it: these surfaces are consistent
 * WITH EACH OTHER. A per-page assertion cannot fail when a new page arrives spelling its header a
 * new way, and that is the failure worth catching.
 *
 * ## Two rails, because one rail was not the product
 *
 * The first version of this spec walked the agency rail only, and that is how `/app/integrations`
 * stayed the one rail surface in the product with no shared head: the Connection Hub wrote its own
 * `<h2>` when it replaced the provider grid, every agency route was consistent, and the spec that
 * existed to catch exactly this could not see the page. Sweeping the advertiser portal found it in
 * one pass.
 *
 * So the claim is now made twice, once per portal, each against its own sign-in. An agency owner
 * cannot reach `/app/*` and an advertiser cannot reach `/agency/*`, so a single session walking both
 * would be asserting against a login redirect — and passing.
 */
const AGENCY_RAIL = [
  '/agency/dashboard',
  '/agency/clients',
  '/agency/projects',
  '/agency/portfolio',
  '/agency/requests',
  '/agency/messages',
  '/agency/billing',
  '/agency/integrations',
  '/agency/campaigns',
  '/agency/analytics',
  '/agency/content',
  '/agency/reports',
  '/agency/alerts',
  '/agency/tasks',
  '/agency/files',
  '/agency/short-links',
  '/agency/team',
  '/agency/subscriptions',
  '/agency/settings',
]

const ADVERTISER_RAIL = [
  '/app/dashboard',
  '/app/projects',
  '/app/portfolio',
  '/app/campaigns',
  '/app/content',
  '/app/tasks',
  '/app/files',
  '/app/short-links',
  '/app/analytics',
  '/app/reports',
  '/app/recommendations',
  '/app/alerts',
  '/app/spend-limits',
  '/app/integrations',
  '/app/subscriptions',
  '/app/settings',
]

/** The shared header, by the testid each surface gives it. Reports kept its older `-hero` name. */
const HEAD = '[data-testid$="-intro"], [data-testid="reports-hero"]'

/**
 * Wait for the page, not for the shell — and the difference is a false failure.
 *
 * `main` is part of the authenticated layout, so it is visible the instant the route mounts, before
 * any query has answered. Counting heads at that moment reported `/app/portfolio` and
 * `/app/integrations` as headless when both draw exactly one head a second later; probing the two
 * routes directly showed the head present and every request answering 200. So the thing to wait for
 * is the head itself, and a route that never grows one simply spends the budget and is reported.
 *
 * Returned rather than asserted, because every test here collects ALL offenders: a walk that stops
 * at the first bad surface tells you about one page per run.
 */
async function settle(page: import('@playwright/test').Page, route: string): Promise<number> {
  await expect(page.locator('main'), `${route} never rendered`).toBeVisible({ timeout: 30_000 })

  try {
    await expect(page.locator(HEAD)).toHaveCount(1, { timeout: 20_000 })

    return 1
  } catch {
    return page.locator(HEAD).count()
  }
}

function railSuite(portal: string, RAIL: readonly string[], storageState: string) {
  test.describe(portal, () => {
    test.use({ storageState })

    test('every rail surface answers «where am I» in the same place', async ({ page }) => {
      test.setTimeout(RAIL.length * 15_000 + 60_000)

      await chooseAProject(page)

      const missing: string[] = []

      for (const route of RAIL) {
        await page.goto(route)
        const heads = await settle(page, route)
        if (heads !== 1) missing.push(`${route} (drew ${heads})`)
      }

      expect(missing, `these surfaces do not draw exactly one shared head:\n  ${missing.join('\n  ')}`).toEqual([])
    })

    test('no rail surface prints a value that never arrived', async ({ page }) => {
      test.setTimeout(RAIL.length * 15_000 + 60_000)

      await chooseAProject(page)

      const offenders: string[] = []

      for (const route of RAIL) {
        await page.goto(route)
        await settle(page, route)

        const text = (await page.locator('main').innerText()).replace(/\s+/g, ' ')
        const found = text.match(/\b(undefined|NaN|\[object Object\])\b/)
        if (found) offenders.push(`${route}: ${found[0]}`)
      }

      expect(offenders, `these surfaces printed a placeholder:\n  ${offenders.join('\n  ')}`).toEqual([])
    })

    test('no rail surface scrolls sideways on a phone', async ({ page }) => {
      test.setTimeout(RAIL.length * 15_000 + 60_000)

      /*
       * A page FIRST, then the language.
       *
       * `switchToEnglish` writes to `localStorage`, and on `about:blank` — where a fresh context
       * starts — the browser refuses it outright with a SecurityError. The toggle needs an origin to
       * belong to.
       */
      await page.setViewportSize({ width: 390, height: 844 })
      await page.goto(RAIL[0]!)
      await switchToEnglish(page)
      await chooseAProject(page)

      const offenders: string[] = []

      for (const route of RAIL) {
        await page.goto(route)
        await settle(page, route)

        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        )
        if (overflow > 1) offenders.push(`${route}: ${overflow}px`)
      }

      expect(offenders, `these surfaces scroll sideways at 390px:\n  ${offenders.join('\n  ')}`).toEqual([])
    })
  })
}

/**
 * Campaigns, Analytics and Content are project-scoped: with nothing chosen they correctly render
 * «pick a project» instead of a head, which is a different claim from the one under test.
 */
async function chooseAProject(page: import('@playwright/test').Page) {
  const projects = (
    await (
      await page.request.get('/api/v1/projects', {
        headers: {
          Accept: 'application/json',
          Origin: process.env.E2E_ORIGIN ?? 'http://localhost:5273',
        },
      })
    ).json()
  ).data as Array<{ id: string; name: string }> | null

  if (projects && projects.length > 0) await selectProject(page, projects[0]!.id)
}

railSuite('the agency rail', AGENCY_RAIL, AUTH.owner)
railSuite('the advertiser rail', ADVERTISER_RAIL, AUTH.advertiser)
