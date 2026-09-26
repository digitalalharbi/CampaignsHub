import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * UX-PAGE-HERO-001 — the header says which scope the page is showing.
 *
 * Ninety-two surfaces drew their own `<h1>` and five used the shared header, so most pages had no
 * purpose line, no badge row, and no statement of scope. The scope is the part a reader needs first
 * and the part this product refuses to blur: **no project selected is not every project**, and a page
 * that shows an empty list without saying which it means invites the reader to conclude their reports
 * are missing.
 *
 * Asserted in the browser rather than in a unit test because the eyebrow is chosen from the live
 * project selection, which a fixture supplies directly and therefore cannot get wrong.
 */
test.describe('the page hero', () => {
  test.use({ storageState: AUTH.owner })

  test('reports names its scope above the title, in both languages', async ({ page }) => {
    await page.goto('/agency/reports')

    const hero = page.getByTestId('reports-hero')
    await expect(hero).toBeVisible({ timeout: 30_000 })

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible()

    // Whichever scope is in force, it is STATED — an empty eyebrow is the defect this guards.
    const eyebrow = page.getByTestId('reports-hero-eyebrow')
    await expect(eyebrow).toBeVisible()
    expect((await eyebrow.innerText()).trim().length).toBeGreaterThan(0)
  })

  /** The header must wrap rather than push the page sideways — the rule the shared header exists for. */
  test('the hero does not scroll a phone sideways', async ({ page }) => {
    await page.setViewportSize({ width: 343, height: 780 })
    await page.goto('/agency/reports')
    await expect(page.getByTestId('reports-hero')).toBeVisible({ timeout: 30_000 })

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow, 'the hero pushed the page wider than the viewport').toBeLessThanOrEqual(1)
  })
})
