import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * `/admin/audit` answers the four questions OPS-002 asks of it.
 *
 * The requirement is «an audit trail for every subscription change, payment, approval decision and
 * permission grant — who, when, why, readable from /admin». The trail existed and could not answer
 * any of those four by itself: the platform log runs to thousands of rows, `user.login` alone is over
 * half of them, and an entry identified its actor with a UUID.
 *
 * These assert on what a person can DO with the page — narrow it to a category, and read who acted —
 * rather than on any particular entry, because the entries are whatever the demo environment has
 * done and will change.
 */
test.describe('the platform audit trail', () => {
  test.use({ storageState: AUTH.admin })

  async function openAudit(page: import('@playwright/test').Page) {
    await page.goto('/admin/audit')
    await expect(page.locator('main')).toBeVisible()
    await expect(page.getByTestId('audit-categories')).toBeVisible({ timeout: 20000 })
  }

  test('the four categories OPS-002 names are offered', async ({ page }) => {
    await openAudit(page)

    for (const key of ['subscriptions', 'payments', 'approvals', 'permissions']) {
      await expect(page.getByTestId(`audit-category-${key}`)).toBeVisible()
    }
  })

  /**
   * A filter narrows the trail. The check is that the result CHANGES — a filter that quietly returns
   * the whole log looks identical to one that works, and is the more likely failure.
   */
  test('choosing a category actually narrows what is shown', async ({ page }) => {
    await openAudit(page)
    await expect(page.getByTestId('audit-entries')).toBeVisible({ timeout: 20000 })

    const all = await page.getByTestId('audit-entries').locator('li').count()
    expect(all).toBeGreaterThan(0)

    await page.getByTestId('audit-category-subscriptions').click()
    await expect.poll(async () => {
      const list = page.getByTestId('audit-entries')
      return (await list.count()) === 0 ? 0 : await list.locator('li').count()
    }, { timeout: 20000 }).toBeLessThan(all)
  })

  /**
   * «Who» is answered with a name.
   *
   * An unattended lifecycle change has no actor, and the page says «the system» rather than leaving a
   * blank — a blank reads as missing data where the truth is that nobody did it.
   */
  test('every entry says who acted, in words', async ({ page }) => {
    await openAudit(page)
    await expect(page.getByTestId('audit-entries')).toBeVisible({ timeout: 20000 })

    const rows = await page.getByTestId('audit-entries').locator('li').evaluateAll((els) =>
      els.slice(0, 10).map((el) => (el as HTMLElement).innerText),
    )

    expect(rows.length).toBeGreaterThan(0)
    for (const row of rows) {
      // A UUID standing where a name should be is the defect this guards against.
      expect(row).not.toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-/m)
    }
  })

  /**
   * An empty category says nothing has happened yet — it does not look broken.
   *
   * Payments have no live charging path, so this list is genuinely empty in every environment. A bare
   * blank panel there reads as a page that failed to load.
   */
  test('an empty category explains itself rather than looking broken', async ({ page }) => {
    await openAudit(page)

    await page.getByTestId('audit-category-payments').click()

    /*
     * Wait for the query to SETTLE, not for the page to have some text on it.
     *
     * The first version polled `main.innerText().length > 50`, which the navigation rail satisfies on
     * its own — so it read the page mid-load, while the skeletons were up and neither the list nor
     * the empty-state existed yet, and reported a defect that was not there. The settled state is
     * «either entries or an explanation», which is exactly what this test is about.
     */
    await expect.poll(async () => {
      const hasEntries = (await page.getByTestId('audit-entries').count()) > 0
      const explained = /لم يحدث شيء منه بعد|Nothing of this kind has happened yet/.test(await page.locator('main').innerText())

      return hasEntries || explained
    }, { timeout: 20000, message: 'an empty category must say so; silence reads as a page that failed to load' }).toBe(true)
  })
})
