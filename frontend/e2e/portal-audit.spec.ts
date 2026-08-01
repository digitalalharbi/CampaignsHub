import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * Every route in a portal leads somewhere real (REVIEW-001).
 *
 * Audited by WALKING the product rather than by reading the router: a route can exist, answer 200
 * and still be a page that tells the customer nothing. The two failures this catches are the ones a
 * route table cannot show — a nav link that goes nowhere, and a page that renders but is empty.
 */

/**
 * A page is "empty" when the shell rendered and the content area did not.
 *
 * Measured AFTER the content area actually has something in it, because `goto` resolves on load and
 * React renders after that — measuring immediately reports every page as empty, which is a broken
 * test rather than a broken product.
 */
async function contentLength(page: Page): Promise<number> {
  const main = page.locator('main')
  await expect(main).toBeVisible({ timeout: 20000 })
  await expect
    .poll(async () => (await main.innerText()).trim().length, { timeout: 20000 })
    .toBeGreaterThan(0)

  return (await main.innerText()).trim().length
}

test.describe('the advertiser portal', () => {
  test.use({ storageState: AUTH.advertiser })

  /**
   * The four unbuilt modules are GONE, not disguised.
   *
   * They rendered a card saying the module was «part of a later phase» while claiming the
   * foundation was in place — roadmap copy served as a page. None was linked from anywhere, so the
   * only way to reach one was to type its URL and be shown something that looked built.
   */
  test('the removed modules answer as routes that do not exist', async ({ page }) => {
    for (const path of ['/app/approvals', '/app/tracking', '/app/optimization', '/app/opportunities']) {
      await page.goto(path)
      // The not-found page, not a card explaining the roadmap.
      await expect(page.getByText(/later phase|قريبًا/i), `${path} still shows a placeholder`).toHaveCount(0)
    }
  })

  /** Notifications had a real page all along; the placeholder sat in front of it. */
  test('notifications leads to the page that actually exists', async ({ page }) => {
    await page.goto('/app/notifications')
    await expect(page).toHaveURL(/\/app\/account\/notifications/)
    expect(await contentLength(page)).toBeGreaterThan(40)
  })

  /**
   * Every link in the rail resolves to a page with content.
   *
   * Walked from the RAIL rather than from a list in the test, so a link added later is audited
   * without anybody remembering to add it here.
   */
  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    expect(hrefs.length, 'the advertiser rail has no links').toBeGreaterThan(3)

    for (const href of hrefs) {
      await page.goto(href)
      await expect(page.getByText(/later phase/i), `${href} is a placeholder`).toHaveCount(0)
      expect(await contentLength(page), `${href} rendered an empty page`).toBeGreaterThan(40)
    }
  })
})

test.describe('the agency portal', () => {
  test.use({ storageState: AUTH.owner })

  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/agency')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/agency"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    expect(hrefs.length, 'the agency rail has no links').toBeGreaterThan(3)

    for (const href of hrefs) {
      await page.goto(href)
      await expect(page.getByText(/later phase/i), `${href} is a placeholder`).toHaveCount(0)
      expect(await contentLength(page), `${href} rendered an empty page`).toBeGreaterThan(40)
    }
  })
})
