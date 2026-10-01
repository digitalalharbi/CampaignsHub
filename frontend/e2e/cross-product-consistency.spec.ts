import { expect, test } from '@playwright/test'
import { AUTH, selectProject, switchToEnglish } from './helpers'

/**
 * PRODUCT-VISUAL-001 §44 — the walk, as a test instead of as a promise.
 *
 * ## What this holds
 *
 * A reader crossing the authenticated rail must meet ONE product, not eleven pages that each
 * invented their own header. Three things are checked on every surface a rail link reaches, in both
 * writing directions and at both widths that matter:
 *
 *  - exactly one shared head, so «where am I» is answered in the same place every time;
 *  - no sideways scroll, because content reachable only by dragging is content a phone user will
 *    not find;
 *  - no `undefined`, `NaN` or `[object Object]` reaching a reader, which is what a page prints when
 *    it formats a figure that never arrived.
 *
 * ## Why one spec and not eleven assertions spread over eleven files
 *
 * The claim is about the product rather than about any page in it: these eleven are consistent WITH
 * EACH OTHER. A per-page assertion cannot fail when a twelfth page arrives spelling its header a
 * new way, and that is the failure worth catching.
 */
test.use({ storageState: AUTH.owner })

const RAIL = [
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

/** The shared header, by the testid each surface gives it. Reports kept its older `-hero` name. */
const HEAD = '[data-testid$="-intro"], [data-testid="reports-hero"]'

test('every rail surface answers «where am I» in the same place', async ({ page }) => {
  test.setTimeout(RAIL.length * 15_000 + 60_000)

  const projects = (await (await page.request.get('/api/v1/projects', {
    headers: { Accept: 'application/json', Origin: process.env.E2E_ORIGIN ?? 'http://localhost:5273' },
  })).json()).data as Array<{ id: string; name: string }> | null

  // Campaigns, Analytics and Content are project-scoped: with nothing chosen they correctly render
  // «pick a project» instead of a head, which is a different claim from the one under test.
  if (projects && projects.length > 0) await selectProject(page, projects[0]!.id)

  const missing: string[] = []

  for (const route of RAIL) {
    await page.goto(route)
    await expect(page.locator('main'), `${route} never rendered`).toBeVisible({ timeout: 30_000 })

    if (await page.locator(HEAD).count() !== 1) missing.push(route)
  }

  expect(missing, `these surfaces do not draw exactly one shared head:\n  ${missing.join('\n  ')}`).toEqual([])
})

test('no rail surface prints a value that never arrived', async ({ page }) => {
  test.setTimeout(RAIL.length * 15_000 + 60_000)

  const offenders: string[] = []

  for (const route of RAIL) {
    await page.goto(route)
    await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })

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
   * `switchToEnglish` writes to `localStorage`, and on `about:blank` — where a fresh context starts
   * — the browser refuses it outright with a SecurityError. The toggle needs an origin to belong to.
   */
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto(RAIL[0]!)
  await switchToEnglish(page)

  const offenders: string[] = []

  for (const route of RAIL) {
    await page.goto(route)
    await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    if (overflow > 1) offenders.push(`${route}: ${overflow}px`)
  }

  expect(offenders, `these surfaces scroll sideways at 390px:\n  ${offenders.join('\n  ')}`).toEqual([])
})
