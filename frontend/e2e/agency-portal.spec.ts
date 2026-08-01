import { expect, test, type Page } from '@playwright/test'
import { AUTH, untranslatedChrome } from './helpers'

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

    for (const href of hrefs) {
      await page.goto(href)
      const main = page.locator('main')
      await expect(main).toBeVisible({ timeout: 20000 })
      await expect.poll(async () => (await main.innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(40)
    }
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
