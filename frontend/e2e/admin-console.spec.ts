import { expect, test } from '@playwright/test'
import { AUTH, untranslatedChrome } from './helpers'

/**
 * `/admin` is a console the owner can actually run the platform from (ADMIN-100).
 *
 * It used to be four counters and two bar-lists that printed database codes — `self_serve_company`,
 * `trial` — at a reader, in an Arabic-first interface. What it could not answer was the question an
 * owner opens it with: how is the platform doing, and what needs me today.
 */
test.describe('the platform console', () => {
  test.use({ storageState: AUTH.admin })

  test('answers how the platform is doing, with real charts over real data', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.getByTestId('platform-overview')).toBeVisible()

    // Four charts, each drawn from the API rather than a bundled array.
    for (const id of ['growth-chart', 'subscription-status', 'tenants-by-type', 'tenants-by-plan']) {
      const chart = page.getByTestId(id)
      await expect(chart, `${id} is missing`).toBeVisible()
      await expect(chart.locator('svg.recharts-surface').first()).toBeVisible()
    }

    // The growth line has points; an empty chart frame would pass a "is it visible" check.
    await expect(page.getByTestId('growth-chart').locator('.recharts-line-curve').first()).toBeAttached()
  })

  /**
   * The money figure is a COMMITMENT and the page says so.
   *
   * CampaignsHub does not charge tenants yet. A console that showed committed subscription value as
   * money in the bank would be the most expensive untruth in the product.
   */
  test('calls committed subscription value what it is', async ({ page }) => {
    await page.goto('/admin')

    // The figure and the qualification travel together: the card says «committed», and the note
    // under it says the collection side is not built. Asserted in whichever language the console
    // opens in, because the honesty is the claim — not the wording of one translation.
    const card = page.getByTestId('committed-monthly')
    await expect(card).toBeVisible()
    await expect(card).toContainText(/قيمة الاشتراكات شهريًا|Committed monthly/)
    await expect(page.getByTestId('collection-note')).toContainText(/غير مفعّل بعد|not live yet/)

    // Never the word that would make it sound collected.
    await expect(page.getByTestId('platform-overview').getByText(/\brevenue\b/i)).toHaveCount(0)
  })

  /** Database codes are not words, and this interface is Arabic first. */
  test('names account types and states instead of printing their codes', async ({ page }) => {
    await page.goto('/admin')
    const body = await page.locator('main').innerText()

    for (const code of ['self_serve_company', 'in_house_team', 'past_due', 'trialing']) {
      expect(body, `the raw code ${code} is on the page`).not.toContain(code)
    }
  })

  /** What needs attention leads somewhere — a count with no route is a dead end. */
  test('every attention row opens the page that answers it', async ({ page }) => {
    await page.goto('/admin')
    const rows = page.getByTestId('attention').locator('a')
    const count = await rows.count()

    for (let i = 0; i < count; i++) {
      const href = await rows.nth(i).getAttribute('href')
      expect(href, 'an attention row leads nowhere').toBeTruthy()

      await page.goto(href!)
      await expect(page.locator('main')).toBeVisible()
      await expect.poll(async () => (await page.locator('main').innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(40)
      await page.goBack()
    }
  })

  /** It reads on a phone, and it does not scroll sideways. */
  test('holds together on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/admin')
    await expect(page.getByTestId('platform-overview')).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    )
    expect(overflow, 'the console scrolls sideways on a phone').toBe(false)
  })
})

/**
 * The console speaks the reader's language too (ADMIN-100 / APP-100 standard).
 *
 * Held to the same measurement as the other three portals: a walk of every rail link in English,
 * asserting zero Arabic — so a section added later is checked without anybody remembering to.
 */
test.describe('the platform console, in English', () => {
  test.use({ storageState: AUTH.admin })

  test('no section is left in Arabic when the language is English', async ({ page }) => {
    await page.goto('/admin')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/admin"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    await page.getByRole('button', { name: 'Toggle language' }).first().click()
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
})
