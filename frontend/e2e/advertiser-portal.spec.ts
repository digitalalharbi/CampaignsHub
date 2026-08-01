import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * `/app` is the advertiser's portal, and it speaks the reader's language (APP-100).
 *
 * The dashboard was **Arabic only**. Choosing English flipped `dir` to `ltr` and left ninety-odd
 * Arabic words on the page — the heading, the objective filter, every KPI label, the demo badge.
 * An interface that changes direction while its content does not reads as broken rather than as
 * unfinished, and it is the flagship page of this portal.
 */
test.describe('the advertiser portal', () => {
  test.use({ storageState: AUTH.advertiser })

  const arabicWords = (text: string) => (text.match(/[؀-ۿ]+/g) ?? []).length

  async function toggleLanguage(page: Page) {
    await page.getByRole('button', { name: 'Toggle language' }).first().click()
  }

  test('the dashboard is genuinely bilingual, not just re-directed', async ({ page }) => {
    await page.goto('/app/dashboard')
    const main = page.locator('main')
    await expect(main).toBeVisible()

    // Arabic first — the product default.
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    await expect.poll(async () => arabicWords(await main.innerText()), { timeout: 20000 }).toBeGreaterThan(20)

    await toggleLanguage(page)
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    // …and in English, nothing Arabic is left behind. Not "fewer" — none.
    await expect
      .poll(async () => arabicWords(await main.innerText()), { timeout: 20000 })
      .toBe(0)
  })

  /**
   * The KPI labels are built inside a memo, so the language has to be one of its inputs.
   *
   * Leaving it out froze them in whichever language the page first rendered in — the heading
   * translated and the numbers beside it did not, which is the most confusing half-state of all.
   */
  test('the objective KPIs re-label when the language changes', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.locator('main')).toBeVisible()
    await expect(page.getByText('الوصول').first()).toBeVisible({ timeout: 20000 })

    await toggleLanguage(page)
    await expect(page.getByText('Reach').first()).toBeVisible({ timeout: 20000 })
    await expect(page.getByText('الوصول')).toHaveCount(0)
  })

  /** Every rail link opens a page with content — the advertiser's own sections, not the agency's. */
  test('every rail link opens a page that is not empty', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])
    expect(hrefs.length).toBeGreaterThan(3)

    for (const href of hrefs) {
      await page.goto(href)
      const main = page.locator('main')
      await expect(main).toBeVisible({ timeout: 20000 })
      await expect.poll(async () => (await main.innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(40)
      // The multi-client tooling belongs to /agency and must not appear here.
      await expect(page.getByRole('navigation').first().locator('a[href^="/agency"]')).toHaveCount(0)
    }
  })

  /**
   * Every section of this portal speaks English when English is chosen (APP-100).
   *
   * Written as a WALK rather than a list, so a section added later is measured without anybody
   * remembering to add it here — and asserted as zero rather than "fewer", because a page that is
   * mostly translated is the state that reads as broken.
   *
   * Arabic inside `<code>`/`<pre>`, and the language toggle's own «ع» label, are not content.
   */
  test('no section is left in Arabic when the language is English', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByRole('navigation').first()).toBeVisible()

    const hrefs = await page.getByRole('navigation').first().locator('a[href^="/app"]')
      .evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href')!))])

    await toggleLanguage(page)
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    const stillArabic: string[] = []
    for (const href of hrefs) {
      await page.goto(href)
      await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
      // Give the section's own queries a moment to resolve before reading its text.
      await expect.poll(async () => (await page.locator('main').innerText()).trim().length, { timeout: 20000 })
        .toBeGreaterThan(0)

      const leftover = await page.locator('main').evaluate((el) => {
        const text = (el as HTMLElement).innerText ?? ''
        return (text.match(/[\u0600-\u06FF]+/g) ?? []).slice(0, 6)
      })
      if (leftover.length > 0) stillArabic.push(`${href}: ${leftover.join(' ')}`)
    }

    expect(stillArabic, `these sections are still Arabic under dir=ltr:\n${stillArabic.join('\n')}`).toEqual([])
  })

  test('the dashboard holds together on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto('/app/dashboard')
    await expect(page.locator('main')).toBeVisible()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    )
    expect(overflow, 'the advertiser dashboard scrolls sideways on a phone').toBe(false)
  })
})

/**
 * Language and theme are REMEMBERED (APP-100).
 *
 * They were not. The sidebar's collapsed state was persisted while the two choices a customer
 * actually notices were not, so choosing English or dark mode lasted until the next full page load
 * and then silently reverted — every bookmark, refresh and new tab put them back into Arabic and
 * light with no explanation.
 *
 * It survived clicking around inside the SPA, which is the path a manual check takes, and only broke
 * on the full navigations an automated walk performs.
 */
test.describe('remembered preferences', () => {
  test.use({ storageState: AUTH.advertiser })

  test('the chosen language survives a full page load', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

    await page.getByRole('button', { name: 'Toggle language' }).first().click()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    // A RELOAD, not a client-side navigation — the case that was broken.
    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')

    // …and a hard navigation to another section keeps it too.
    await page.goto('/app/reports')
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  })

  test('the chosen theme survives a full page load', async ({ page }) => {
    await page.goto('/app/dashboard')
    const before = await page.locator('html').getAttribute('data-theme')

    await page.getByRole('button', { name: 'Toggle theme' }).first().click()
    const after = await page.locator('html').getAttribute('data-theme')
    expect(after).not.toBe(before)

    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('data-theme', after!)
  })
})
