import { expect, test, type Page } from '@playwright/test'
import { AUTH, untranslatedChrome, walkRail } from './helpers'

/**
 * `/agency` is the agency's portal, and it is not the advertiser's with extra links (AGENCY-100).
 *
 * The claim under test is the one REG-003 exists for: an agency's work starts from a CLIENT, and the
 * multi-client tooling belongs here and nowhere else. Plus the same standards the other portals are
 * now held to — real content behind every rail link, English that is actually English, and a phone
 * layout that does not scroll sideways.
 */
test.describe('the agency portal', () => {
  test.use({ storageState: AUTH.owner })

  const arabicWords = (text: string) => (text.match(/[؀-ۿ]+/g) ?? []).length

  async function toggleLanguage(page: Page) {
    await page.getByRole('button', { name: 'Toggle language' }).first().click()
  }

  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/agency')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/agency"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])
    expect(hrefs.length).toBeGreaterThan(5)

    /*
     * A budget that grows with the rail, because the loop does.
     *
     * This walked every destination under Playwright's flat 30-second default, which is a fixed
     * budget for a loop whose length is decided by the product — and each step already allows two
     * 20-second waits of its own. It only ever passed because the pages were quick; the first slow
     * run exceeded it, and the reported failure was «main not found» on whichever page happened to
     * be mid-navigation when the test was torn down, which points at nothing.
     *
     * `portal-audit.spec.ts` reached the same conclusion and uses the same shape. This is not a
     * timeout raised to make a failure go away: the count is known, and a per-destination allowance
     * is the honest way to express what the test is actually waiting for.
     */
    test.setTimeout(15_000 + hrefs.length * 8_000)

    /*
     * `walkRail` rather than a bare loop — the same walk `portal-audit.spec.ts` already uses.
     *
     * This test failed on webkit reporting `locator('main')` not found, and that sentence is all it
     * reported. Which of the links? What was on screen? The last time somebody chased this the
     * screenshot showed a COMPLETELY BLANK document — no shell, no navigation, not even the error
     * boundary — which is the application failing to mount, not a page rendering empty. Those are
     * different faults for different people, and the bare loop could not tell them apart.
     *
     * `walkRail` names the href, the document status, the URL the browser ended on, whether `<main>`
     * and `<nav>` exist, the body text, and anything the console, the page or a request said while
     * it failed. Nothing is retried and no timeout is raised: the assertion is the same assertion,
     * and it now arrives with the evidence attached.
     */
    await walkRail(page, hrefs)
  })

  /**
   * The multi-client tooling is the agency's, and the advertiser portal must not offer it.
   *
   * This is the regression REG-003 was opened for: `/app` had no portal guard, so an agency operator
   * used the advertiser portal's copy of everything and the two looked like one product.
   */
  test('the client roster is offered here and nowhere else', async ({ page }) => {
    await page.goto('/agency')
    const rail = page.getByRole('navigation').first()

    await expect(rail.locator('a[href="/agency/clients"]')).toHaveCount(1)
    // …and the advertiser's rail, which this operator can also reach, does not carry it.
    await expect(rail.locator('a[href^="/app"]')).toHaveCount(0)
  })

  test('no section is left in Arabic when the language is English', async ({ page }) => {
    await page.goto('/agency')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/agency"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    // Same growing loop, same reasoning as above — and this one also loads each page twice over,
    // once to render and once to read all of its text back out.
    test.setTimeout(15_000 + hrefs.length * 8_000)

    await toggleLanguage(page)
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    const stillArabic: string[] = []
    for (const href of hrefs) {
      await page.goto(href)
      await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
      await expect.poll(async () => (await page.locator('main').innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(0)

      const leftover = await untranslatedChrome(page)
      if (leftover.length > 0) stillArabic.push(`${href}: ${leftover.join(' ')}`)
    }

    expect(stillArabic, `these sections are still Arabic under dir=ltr:\n${stillArabic.join('\n')}`).toEqual([])
  })

  test('the agency overview holds together on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/agency')
    await expect(page.locator('main')).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    )
    expect(overflow, 'the agency overview scrolls sideways on a phone').toBe(false)
  })
})
