import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * ANALYTICS-REPORT-LOGIC-001 — the report's sections on the one board, driven in the browser.
 *
 * Three behaviours the Owner asked for: a click on a platform or a campaign in any chart filters the whole page (and survives a
 * reload, because it is the URL's); «مقارنة» compares period / platform / campaign / project and says «غير متاح» for a side
 * with nothing; and «إنشاء تقرير من هذا العرض» turns the view's filters and period into a /r/ link through the report builder.
 *
 * The figures are the seeded demo world's and will change, so these assert on behaviour and on the contract (an address, a
 * filter, a link), never on a number.
 */
test.describe('the analytics board carries the report', () => {
  test.use({ storageState: AUTH.advertiser })

  async function openSummary(page: Page) {
    await page.goto('/app/analytics')
    await expect(page.locator('main')).toBeVisible()
  }

  test('a click on a platform filters the whole page, survives a reload and clears on a second click', async ({ page }) => {
    await openSummary(page)
    const cards = page.getByTestId('board-platform-summary')
    await expect(cards).toBeVisible({ timeout: 30000 })
    const platform = cards.locator('[data-testid^="board-platform-card-"]:not([data-testid="board-platform-card-all"]):not([data-testid="board-platform-card-bar"])').first()
    await expect(platform).toBeVisible()
    const key = (await platform.getAttribute('data-testid'))!.replace('board-platform-card-', '')

    await platform.click()
    await expect(page).toHaveURL(new RegExp(`provider=${key}`))
    await expect(page.getByTestId(`board-platform-card-${key}`)).toHaveAttribute('aria-selected', 'true')

    await page.reload()
    await expect(page).toHaveURL(new RegExp(`provider=${key}`))
    await expect(page.getByTestId(`board-platform-card-${key}`)).toHaveAttribute('aria-selected', 'true', { timeout: 30000 })

    await page.getByTestId(`board-platform-card-${key}`).click()
    await expect(page).not.toHaveURL(/provider=/)
  })

  test('the platforms section ranks and sorts, and a bar click filters like a card', async ({ page }) => {
    await page.goto('/app/analytics?tab=s_platforms')
    const list = page.getByTestId('board-rank-list')
    await expect(list).toBeVisible({ timeout: 30000 })
    await page.getByTestId('board-rank-sort-clicks').click()
    await expect(list).toHaveAttribute('data-sort', 'clicks')
    await page.getByTestId('board-rank-sort-clicks').click()
    await expect(list).toHaveAttribute('data-dir', 'asc')

    const bar = list.locator('[data-testid^="board-rank-"][aria-pressed]').filter({ hasNot: page.locator('[data-testid^="board-rank-sort"]') }).first()
    const key = (await bar.getAttribute('data-testid'))!.replace('board-rank-', '')
    await bar.click()
    await expect(page).toHaveURL(new RegExp(`provider=${key}`))
  })

  test('«مقارنة» compares in four ways and keeps the choice in the address', async ({ page }) => {
    await page.goto('/app/analytics?tab=s_compare')
    const board = page.getByTestId('compare-board')
    await expect(board).toBeVisible({ timeout: 30000 })
    await expect(board).toHaveAttribute('data-mode', 'period')
    // A period is compared with the one before it: either real figures with a verdict, or the honest «not available» — never a blank.
    await expect(page.getByTestId('compare-kpis')).toBeVisible({ timeout: 30000 })

    for (const mode of ['platform', 'campaign', 'project'] as const) {
      await page.getByTestId(`compare-mode-${mode}`).click()
      await expect(board).toHaveAttribute('data-mode', mode)
      await expect(page).toHaveURL(new RegExp(`cmp=${mode}`))
    }
    await page.reload()
    await expect(page.getByTestId('compare-board')).toHaveAttribute('data-mode', 'project', { timeout: 30000 })
  })

  test('a side with nothing is «غير متاح», never a figure', async ({ page }) => {
    await page.goto('/app/analytics?tab=s_compare&from=2019-01-01&to=2019-01-07')
    const board = page.getByTestId('compare-board')
    await expect(board).toBeVisible({ timeout: 30000 })
    await expect(board.getByText(/غير متاح|Not available/).first()).toBeVisible({ timeout: 30000 })
    await expect(page.getByTestId('compare-overlay')).toHaveCount(0)
  })
})

test.describe('«إنشاء تقرير من هذا العرض»', () => {
  test.use({ storageState: AUTH.owner })

  test('offers the report to a reader who may share, and answers with a /r/ link or says why not', async ({ page }) => {
    await page.goto('/agency/analytics')
    await expect(page.locator('main')).toBeVisible()
    const button = page.getByTestId('report-from-view')
    await expect(button).toBeVisible({ timeout: 30000 })
    await button.click()
    // Either the builder made a link over the view's accounts, or the view had no ad account to report on — and that is said.
    const link = page.getByTestId('report-from-view-url')
    const refusal = page.getByTestId('report-from-view-error')
    await expect(link.or(refusal)).toBeVisible({ timeout: 30000 })
    if (await link.isVisible()) await expect(link).toHaveValue(/\/r\//)
  })

  test('is not offered to a reader without the share permission', async ({ browser }) => {
    const context = await browser.newContext({ storageState: AUTH.viewer })
    const page = await context.newPage()
    await page.goto('/app/analytics')
    await expect(page.locator('main')).toBeVisible()
    await expect(page.getByTestId('report-from-view')).toHaveCount(0)
    await context.close()
  })
})
