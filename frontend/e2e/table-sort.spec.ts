import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * TABLE-SORT-ALIGN-001 — the analytics tables sort, and their numbers sit under their headings.
 *
 * What this file proves and what it does not: every sortable table in the seeded database holds a
 * single row, so reordering cannot be demonstrated here — two rows are needed to have an order at
 * all. `tableSort.test.ts` proves the ordering rule on real multi-row data, including that an absent
 * figure sorts last in both directions. This proves the parts that need a browser: the control is
 * on the header, it declares its state to assistive technology, and it toggles.
 */
test.use({ storageState: AUTH.advertiser })

const openTab = async (page: import('@playwright/test').Page, name: string) => {
  const clicked = await page.locator('main button').evaluateAll((els, n) => {
    const el = els.find((e) => e.textContent?.trim() === n) as HTMLElement | undefined
    if (!el) return false
    el.click()
    return true
  }, name)
  expect(clicked, `tab «${name}» not found`).toBe(true)
  await page.waitForTimeout(2200)
}

test.describe('the analytics tables', () => {
  test('every numeric header is a sort control that declares its direction', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
    await openTab(page, 'المنصات')

    const table = page.locator('main table').first()
    await expect(table.locator('tbody tr')).not.toHaveCount(0)

    // Sorting is offered on this table at all — a table with no `values` renders plain headings.
    const controls = table.locator('thead [data-testid^="sort-"]')
    expect(await controls.count(), 'the platforms table offers no sort controls').toBeGreaterThan(1)

    // It opens sorted by spend, and says so where a screen reader can hear it.
    const spendHeader = table.locator('thead th').nth(1)
    await expect(spendHeader).toHaveAttribute('aria-sort', 'descending')

    await table.locator('thead [data-testid="sort-1"]').click()
    await expect(spendHeader).toHaveAttribute('aria-sort', 'ascending')

    await table.locator('thead [data-testid="sort-1"]').click()
    await expect(spendHeader).toHaveAttribute('aria-sort', 'descending')

    // Sorting one column releases the previous one, so the row never shows two live sort states.
    await table.locator('thead [data-testid="sort-3"]').click()
    await expect(spendHeader).not.toHaveAttribute('aria-sort', /.*/)
  })

  test('numeric columns are centred under their own headings', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
    await openTab(page, 'المنصات')

    const drift = await page.locator('main table').first().evaluate((t) => {
      const table = t as HTMLTableElement
      const heads = [...table.querySelectorAll('thead th')]
      const cells = [...(table.querySelector('tbody tr')?.querySelectorAll('td') ?? [])]

      // Centres, which is what «under the heading» means once a column is centred.
      return heads.slice(1).map((h, i) => {
        const hr = h.getBoundingClientRect()
        const cr = cells[i + 1].getBoundingClientRect()
        return Math.abs((hr.left + hr.width / 2) - (cr.left + cr.width / 2))
      })
    })

    expect(drift.length).toBeGreaterThan(3)
    for (const d of drift) expect(d, `a numeric column drifts ${d}px from its heading`).toBeLessThan(2)
  })
})
