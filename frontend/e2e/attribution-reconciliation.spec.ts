import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * ATTRIBUTION-RECONCILIATION-001 — the four layers and the ledger, on the rendered quality tab.
 *
 * The unit guards prove the engine places each order once and the panel draws what it is given;
 * neither proves the block reaches a screen through the real endpoint on real seeded orders. This
 * opens the Data quality & attribution tab by URL (`?tab=quality` — a label is translated, a query
 * string is not) on the seeded store project, whose `DemoCommerceSeeder` writes orders carrying
 * click ids and UTMs, and reads what rendered.
 */
const PROJECT = 'متجر تجريبي — Demo'

for (const locale of ['ar', 'en'] as const) {
  test.describe(`the reconciled layer on the quality tab (${locale})`, () => {
    test.use({ storageState: AUTH.owner })

    test('places the ledger on the platforms and names every ROAS basis', async ({ page, request }) => {
      await selectProject(page, await seededProject(request, PROJECT))
      await page.goto('/agency/analytics?tab=quality')
      if (locale === 'en') await switchToEnglish(page)

      const block = page.getByTestId('reconciliation')
      await expect(block).toBeVisible({ timeout: 30_000 })

      const rows = block.locator('[data-testid^="reconciliation-row-"]')
      await expect(rows.first()).toBeVisible()
      expect(await rows.count()).toBeGreaterThan(0)

      /* Every platform row carries both ROAS chips, each saying what it was counted on. */
      for (let i = 0; i < (await rows.count()); i++) {
        const row = rows.nth(i)
        await expect(row.locator('[data-testid^="roas-platform_reported-"]')).toHaveCount(1)
        await expect(row.locator('[data-testid^="roas-store_confirmed-"]')).toHaveCount(1)
      }

      /* The ledger: the merchant's references, with an evidence badge on each. */
      const ledger = block.getByTestId('reconciliation-ledger')
      await expect(ledger).toBeVisible()
      const entries = ledger.locator('[data-testid^="ledger-row-"]')
      expect(await entries.count()).toBeGreaterThan(0)

      /* GA4 is stated as its own line — present or absent, never a zero on a platform row. */
      await expect(block.getByTestId('reconciliation-measurement')).toBeVisible()

      /* No cross-platform total anywhere in the block. */
      await expect(block).not.toContainText(locale === 'ar' ? 'إجمالي المنصات' : /platform total/i)
      await expect(page.locator('html')).toHaveAttribute('dir', locale === 'ar' ? 'rtl' : 'ltr')
    })
  })
}
