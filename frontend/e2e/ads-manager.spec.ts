import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * ADS-MANAGER-001 … 007 — «الحملات» is one page: the period's figures over one chart, a delivery filter, the metric set
 * and a search in one row, four levels in one strip, and a table per level with its totals. The advanced view is retired
 * (Owner, 2026-11-25); its tools live here: ticking campaigns, bulk pause/resume behind a confirmation, the comparison,
 * the change history and the CSV export. The old Accounts, Ad groups and Ads addresses land on their level here.
 */
test.describe('the ads manager (agency)', () => {
  test.use({ storageState: AUTH.owner })

  test('one page: figures, levels, no advanced view', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.addInitScript(() => {
      if (!sessionStorage.getItem('ads-manager-reset')) {
        for (const k of ['ch-ads-manager-kpis', 'ch-ads-manager-preset']) localStorage.removeItem(k)
        sessionStorage.setItem('ads-manager-reset', '1')
      }
    })
    await page.goto('/agency/campaigns')

    await expect(page.getByTestId('ads-manager')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByTestId('ads-manager-advanced')).toHaveCount(0)
    for (const [i, k] of ['spend', 'impressions', 'clicks', 'ctr', 'video_views'].entries()) {
      await expect(page.getByTestId(`ads-manager-kpi-${i}`)).toHaveAttribute('data-metric', k)
    }

    for (const level of ['accounts', 'ad_sets', 'ads', 'campaigns']) {
      await page.getByTestId(`ads-manager-level-${level}`).click()
      await expect(page.getByTestId(`ads-manager-level-${level}`)).toHaveAttribute('aria-selected', 'true')
    }

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)
  })

  test('the old level addresses land on their level, the period kept', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    for (const [path, level] of [['/agency/ad-groups', 'ad_sets'], ['/agency/ads', 'ads'], ['/agency/accounts', 'accounts']] as const) {
      await page.goto(`${path}?from=2026-11-01&to=2026-11-10`)
      await expect(page).toHaveURL(new RegExp(`/agency/campaigns\\?.*level=${level}`))
      expect(new URL(page.url()).searchParams.get('from')).toBe('2026-11-01')
      await expect(page.getByTestId(`ads-manager-level-${level}`)).toHaveAttribute('aria-selected', 'true', { timeout: 30_000 })
    }
  })

  test('ticked campaigns: the pause dialog names them before anything is sent; compare and history open', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/campaigns')
    const rows = page.getByTestId('ads-manager-select')
    await expect(rows.first()).toBeVisible({ timeout: 30_000 })
    test.skip((await rows.count()) < 2, 'the seeded project reported fewer than two campaigns in the period')

    let writes = 0
    page.on('request', (r) => { if (r.method() !== 'GET' && /\/campaigns\/[^/]+\/(pause|resume|actions)/.test(r.url())) writes += 1 })

    await rows.nth(0).check()
    await rows.nth(1).check()
    await expect(page.getByTestId('ads-manager-select-all').locator('..')).toContainText(/2/)

    await page.getByTestId('campaigns-bulk-pause').click()
    const dialog = page.getByTestId('campaigns-bulk-dialog')
    await expect(dialog).toBeVisible()
    await expect(dialog.locator('li')).toHaveCount(2)
    await page.keyboard.press('Escape')
    expect(writes, 'opening the dialog sent nothing to a platform').toBe(0)

    await page.getByTestId('ads-manager-compare-open').click()
    await expect(page.getByTestId('ads-manager-compare-dialog')).toBeVisible({ timeout: 30_000 })
    await page.keyboard.press('Escape')

    await page.getByTestId('ads-manager-history').click()
    await expect(page.getByRole('dialog')).toBeVisible()
  })
})
