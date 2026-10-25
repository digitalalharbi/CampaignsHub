import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, selectProject, switchToEnglish } from './helpers'

/**
 * COMMERCE-INSIGHTS-001 — the Store sales tab, against the real API and the seeded demo store.
 *
 * Proves what a mock cannot: the endpoint answers for a real project, its figures reconcile (net sales are gross sales minus refunds,
 * and the daily trend adds up to the headline), and the tab draws them — chart, tables, provenance — without a console error or a
 * sideways scroll on a phone.
 */
test.use({ storageState: AUTH.owner })

interface Insights {
  totals: { orders: number; gross_sales: number; refunds: number; net_sales: number }
  trend: { net_sales: number }[]
  provenance: { currency: string; stores: unknown[] }
  profitability: { available: boolean }
}

async function projectWithOrders(request: import('@playwright/test').APIRequestContext): Promise<{ id: string; data: Insights }> {
  const projects = ((await (await request.get('/api/v1/projects', { headers: API_HEADERS })).json()).data ?? []) as { id: string }[]
  for (const p of projects) {
    const r = await request.get(`/api/v1/projects/${p.id}/commerce/insights`, { headers: API_HEADERS })
    if (!r.ok()) continue
    const data = (await r.json()).data as Insights
    if (data.totals.orders > 0) return { id: p.id, data }
  }
  throw new Error('the demo seed holds no project with store orders')
}

test('the endpoint reconciles on the demo store', async ({ request }) => {
  const { data } = await projectWithOrders(request)
  const t = data.totals

  expect(Math.abs(t.gross_sales - t.refunds - t.net_sales), 'net sales are gross sales minus refunds').toBeLessThan(0.05)
  const trend = data.trend.reduce((a, d) => a + d.net_sales, 0)
  expect(Math.abs(trend - t.net_sales), 'the daily trend adds up to the headline').toBeLessThan(0.05)
  expect(data.provenance.currency).toMatch(/^[A-Z]{3}$/)
  expect(data.profitability.available, 'no cost data exists, and the response says so').toBe(false)
})

test('the tab draws the figures, the chart and the provenance, and keeps its limits in words', async ({ page, request }) => {
  const { id } = await projectWithOrders(request)
  const errors: string[] = []
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  page.on('pageerror', (e) => errors.push(e.message))

  await selectProject(page, id)
  await page.goto('/agency/analytics')
  await switchToEnglish(page)
  await page.getByText('Store sales', { exact: true }).first().click()

  await expect(page.getByTestId('store-sales')).toBeVisible({ timeout: 30_000 })
  await expect(page.getByTestId('sales-kpis')).toContainText('Net sales')
  await expect(page.getByTestId('sales-trend').locator('svg').first()).toBeVisible()
  await expect(page.getByTestId('sales-products').getByRole('row').nth(1)).toBeVisible()
  await expect(page.getByTestId('sales-provenance')).toContainText(/→/)
  await expect(page.getByTestId('sales-profit-unavailable')).toContainText(/no product cost data/)
  await expect(page.getByTestId('sales-retention-unavailable')).toBeVisible()
  expect(errors.filter((e) => !/favicon|manifest/i.test(e))).toEqual([])
})

test('the tab fits a phone without sideways scroll', async ({ page, request }) => {
  const { id } = await projectWithOrders(request)
  await page.setViewportSize({ width: 390, height: 844 })
  await selectProject(page, id)
  await page.goto('/agency/analytics')
  await switchToEnglish(page)
  await page.getByText('Store sales', { exact: true }).first().click()
  await expect(page.getByTestId('store-sales')).toBeVisible({ timeout: 30_000 })

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(1)
})
