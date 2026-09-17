import { expect, test } from '@playwright/test'
import { buildGeneratedClientReport } from './report-builder'
import { API_HEADERS, AUTH, csrfHeaders } from './helpers'

/**
 * REPORT-DRILLDOWN-001 — the PDF's optional platform drill-down, switched on by the operator.
 *
 * Driven through the page Chromium prints (`/reports/print/{token}`), because that page IS the file:
 * off by default, the section is absent; the operator's switch on the reports list turns it on; the
 * printed page then carries one block per platform, with pictures or the absence wording and no
 * campaign — and switching it off takes it out again.
 */
test.use({ storageState: AUTH.owner })

test('the operator switches the PDF platform drill-down on and off, and the print page follows', async ({ page }) => {
  test.setTimeout(180_000)
  const { projectId, reportId } = await buildGeneratedClientReport(page, `E2E PDF drill ${Date.now()}`)

  const printPage = async (type: 'document' | 'presentation') => {
    const res = await page.request.post(`/api/v1/projects/${projectId}/reports/${reportId}/print-token`, {
      headers: await csrfHeaders(page.request),
      data: { type },
    })
    expect(res.status(), await res.text()).toBe(200)
    const token = (await res.json()).data.token as string
    await page.goto(`/reports/print/${token}?type=${type}`)
  }

  await printPage('document')
  await expect(page.locator('.doc-root')).toBeVisible({ timeout: 30_000 })
  await expect(page.getByTestId('print-platform-drilldowns'), 'the drill-down printed without being enabled').toHaveCount(0)
  await expect(page.locator('section.pdd')).toHaveCount(0)

  await page.goto('/reports')
  const toggle = page.getByTestId(`pdf-drilldown-${reportId}`)
  await expect(toggle).toBeVisible({ timeout: 20_000 })
  await expect(toggle).toHaveAttribute('aria-pressed', 'false')
  await toggle.click()
  await expect(toggle).toHaveAttribute('aria-pressed', 'true', { timeout: 15_000 })

  const report = (await (await page.request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data
  expect(report.config?.breakdowns?.pdf?.platform_drilldown).toBe(true)

  for (const type of ['document', 'presentation'] as const) {
    await printPage(type)
    const section = page.locator('section.pdd').first()
    await expect(section, `${type}: no platform drill-down printed after enabling it`).toBeVisible({ timeout: 30_000 })
    await expect(section.locator('[data-testid^="print-drilldown-share-outcome"]')).toBeVisible()
    await expect(section).not.toContainText(/campaign|حملة|الحملات/i)
  }

  await page.goto('/reports')
  await page.getByTestId(`pdf-drilldown-${reportId}`).click()
  await expect(page.getByTestId(`pdf-drilldown-${reportId}`)).toHaveAttribute('aria-pressed', 'false', { timeout: 15_000 })
  await printPage('document')
  await expect(page.locator('.doc-root')).toBeVisible({ timeout: 30_000 })
  await expect(page.getByTestId('print-platform-drilldowns')).toHaveCount(0)
})
