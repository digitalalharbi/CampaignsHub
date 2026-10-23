import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * ADV-CAMPAIGN-TABLES-001 — «المجموعات» and «الإعلانات» as operational tables, in a real browser.
 *
 * The data-carrying case runs as the agency owner on the seeded store project (the only seed with ad-group
 * and ad rows); the advertiser case proves the pages are in the advertiser's own portal, and that the
 * columns, filters and «إدارة» reach the real provider control panel — never an agency surface.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

test.describe('the ads and ad-groups tables (agency, seeded data)', () => {
  test.use({ storageState: AUTH.owner })

  test('ads: rows carry their platform, a search narrows them, and «إدارة» opens the campaign controls on that row', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/ads')

    const table = page.getByTestId('entity-table-ad')
    await expect(table).toBeVisible({ timeout: 30_000 })
    // MGMT-PAGES-001 — the page carries its own KPI strip and trend above the table, not an Analytics tab.
    await expect(page.getByTestId('entity-overview-ad')).toBeVisible()
    await expect(page.getByTestId('entity-overview-ad-trend')).toBeVisible({ timeout: 30_000 })
    const rows = table.locator('tbody tr')
    const before = await rows.count()
    expect(before).toBeGreaterThan(1)

    const manage = page.locator('[data-testid^="entity-manage-"]').first()
    const dialog = page.getByTestId('entity-manage-dialog')
    /*
     * Click, THEN assert, as one retried unit. The table re-sorts and re-renders while its figures settle, and on Firefox a click that
     * landed on a button the next render replaced opened nothing — the dialog «was never found» although the control worked: three
     * CI runs in a row failed here on firefox only and passed on the other two engines, and the failure page showed a fully loaded
     * table with no dialog. A bounded retry of the user's own action is the documented remedy (`expect(...).toPass`); it asserts the same
     * property (clicking «إدارة» opens the campaign controls on that row) and never accepts an unopened dialog.
     */
    await expect(async () => {
      await manage.click({ timeout: 5_000 })
      await expect(dialog).toBeVisible({ timeout: 4_000 })
    }).toPass({ timeout: 40_000 })
    await expect(dialog.locator('[data-focused="true"]')).toHaveCount(1, { timeout: 20_000 })
    await page.keyboard.press('Escape')
    await expect(dialog).toHaveCount(0)

    await page.getByTestId('entity-search-ad').fill('zzzz-no-such-ad')
    await expect(page.getByTestId('entity-no-match-ad')).toBeVisible()
    await page.getByTestId('entity-search-ad').fill('')
    await expect(rows).toHaveCount(before)
  })

  test('ad groups: a column switched off leaves the table and stays off after a reload', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/ad-groups')

    const table = page.getByTestId('entity-table-ad_set')
    await expect(table).toBeVisible({ timeout: 30_000 })
    const headsBefore = await table.locator('th').count()

    await page.getByTestId('entity-columns-toggle-ad_set').click()
    const first = page.getByTestId('entity-columns-ad_set').locator('input[type="checkbox"]').first()
    await first.uncheck()
    await expect(table.locator('th')).toHaveCount(headsBefore - 1)

    await page.reload()
    await expect(page.getByTestId('entity-table-ad_set').locator('th')).toHaveCount(headsBefore - 1, { timeout: 30_000 })

    // Leave the shared storage state as it was found.
    await page.getByTestId('entity-columns-toggle-ad_set').click()
    await page.getByTestId('entity-columns-ad_set').locator('input[type="checkbox"]:not(:checked)').first().check()
  })
})

test.describe('the ads and ad-groups tables (advertiser portal)', () => {
  test.use({ storageState: AUTH.advertiser })

  for (const [path, level] of [['/app/ad-groups', 'ad_set'], ['/app/ads', 'ad']] as const) {
    test(`${path} is the advertiser's own page, with its filters, and no agency link`, async ({ page }) => {
      await page.goto(path)
      await expect(page.getByTestId(`entity-workspace-${level}`)).toBeVisible({ timeout: 30_000 })
      await expect(page.getByTestId(`entity-overview-${level}`)).toBeVisible()
      await expect(page).toHaveURL(new RegExp(`${path}$`))
      await expect(page.getByTestId(`entity-status-${level}`)).toBeVisible()
      await expect(page.getByTestId(`entity-search-${level}`)).toBeVisible()
      await expect(page.locator('main a[href^="/agency"]')).toHaveCount(0)

      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
      expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
    })
  }
})
