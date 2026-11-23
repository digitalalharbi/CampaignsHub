import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * ADS-MANAGER-001 — «الحملات» opens on one simple page: the period's figures over one chart, a delivery filter and a search,
 * four levels in one strip, and a table per level with its totals. «عرض متقدم» keeps the full workspace one click away,
 * and the choice is remembered.
 */
test.describe('the ads manager (agency)', () => {
  test.use({ storageState: AUTH.owner })

  test('opens simple, moves between levels, and remembers the advanced view', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.addInitScript(() => {
      if (!sessionStorage.getItem('ads-manager-reset')) {
        for (const k of ['ch-campaigns-layout', 'ch-ads-manager-kpis', 'ch-ads-manager-preset']) localStorage.removeItem(k)
        sessionStorage.setItem('ads-manager-reset', '1')
      }
    })
    await page.goto('/agency/campaigns')

    await expect(page.getByTestId('ads-manager')).toBeVisible({ timeout: 30_000 })
    for (const [i, k] of ['spend', 'impressions', 'clicks', 'ctr', 'video_views'].entries()) {
      await expect(page.getByTestId(`ads-manager-kpi-${i}`)).toHaveAttribute('data-metric', k)
    }

    for (const level of ['accounts', 'ad_sets', 'ads', 'campaigns']) {
      await page.getByTestId(`ads-manager-level-${level}`).click()
      await expect(page.getByTestId(`ads-manager-level-${level}`)).toHaveAttribute('aria-selected', 'true')
    }

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
    expect(overflow, 'page-level horizontal overflow').toBeLessThanOrEqual(0)

    await page.getByTestId('ads-manager-advanced').click()
    await expect(page.getByTestId('campaigns-simple-view')).toBeVisible({ timeout: 30_000 })
    await page.reload()
    await expect(page.getByTestId('campaigns-simple-view')).toBeVisible({ timeout: 30_000 })
    await page.getByTestId('campaigns-simple-view').click()
    await expect(page.getByTestId('ads-manager')).toBeVisible()
  })
})
