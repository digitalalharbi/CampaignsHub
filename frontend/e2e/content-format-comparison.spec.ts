import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject, selectProject } from './helpers'

/**
 * CONTENT-FORMAT-COMPARISON-001 — «مقارنة أنواع المحتوى» on the three surfaces that draw it, against the DEMO creatives
 * (`DemoCreativesSeeder`, `DemoCreativeAnalysisSeeder` — labelled demo). No live provider is involved.
 *
 * What only a browser can say: the Analytics tab's big format cards, the switches and the drill reach the rendered page; the client's
 * live link draws the part in its «المحتوى» tab without a spend figure; and a regenerated saved report carries it.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'
const LIVE_TOKEN = 'demo-live-report-token'

test.describe('content format comparison', () => {
  test.use({ storageState: AUTH.owner })

  test('Analytics: big cards per format, switchable donut and bars, and a drill into one format', async ({ page, request }) => {
    test.setTimeout(120_000)
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?tab=creative')

    const panel = page.getByTestId('analytics-format-comparison')
    await expect(panel).toBeVisible({ timeout: 30000 })
    await expect(panel.getByTestId('format-cards')).toBeVisible()
    await expect(panel.getByTestId('format-coverage')).toBeVisible()
    expect(await panel.locator('[data-testid^="format-card-results-"]').count()).toBeGreaterThan(1)

    const donut = panel.getByTestId('format-donut')
    await expect(donut).toHaveAttribute('data-basis', 'spend')
    await panel.getByTestId('format-share-results').click()
    await expect(donut).toHaveAttribute('data-basis', 'results')

    await panel.getByTestId('format-metric-ctr').click()
    await expect(panel.getByTestId('format-bars')).toHaveAttribute('data-metric', 'ctr')

    const drill = panel.locator('[data-testid^="format-drill-"]').filter({ hasText: /المحتوى|Ads/ }).first()
    await drill.click()
    await expect(panel.getByTestId('format-drill')).toBeVisible()
    await expect(panel.getByTestId('format-drill-cards').locator('li').first()).toBeVisible({ timeout: 20000 })
    await expect(panel.getByTestId('format-top-creatives')).toBeVisible()
  })

  test('the client link draws the comparison in its content tab, with results and no spend', async ({ page }) => {
    test.setTimeout(120_000)
    await page.context().clearCookies()
    await page.goto(`/r/${LIVE_TOKEN}?view=content`)
    await expect(page.getByTestId('live-mode-content')).toBeVisible({ timeout: 30000 })

    const block = page.getByTestId('report-format-comparison')
    await expect(block).toBeVisible({ timeout: 30000 })
    // REPORT-FORMAT-COMPARE-COMPACT-001 — one compact strip of small parallel cards, no charts or tables, no «—» filler.
    await expect(block.getByTestId('report-format-strip')).toHaveCount(1)
    expect(await block.locator('[data-testid^="format-strip-card-"]').count()).toBeGreaterThan(1)
    await expect(block).not.toContainText(/الإنفاق|Spend|تكلفة النتيجة|Cost\/result|—/)
    await expect(block.getByTestId('format-cards')).toHaveCount(0)
  })

  test('a regenerated saved report carries the comparison page', async ({ page, request }) => {
    test.setTimeout(360_000)
    const projectId = await seededProject(request, STORE_PROJECT)
    const headers = { ...(await csrfHeaders(request)), 'Content-Type': 'application/json' }
    const list = ((await (await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status?: string }>
    const report = list.find((r) => r.status === 'completed')
    expect(report, 'the store project has no completed report').toBeTruthy()

    const regen = await request.post(`/api/v1/projects/${projectId}/reports/${report!.id}/regenerate`, { headers, data: { confirm: true } })
    expect(regen.ok(), `regenerate returned ${regen.status()}`).toBe(true)
    await expect.poll(async () => ((await (await request.get(`/api/v1/projects/${projectId}/reports/${report!.id}`, { headers: API_HEADERS })).json()).data?.status), { timeout: 120_000 }).toBe('completed')

    /*
     * The PDF: the export renders every page on Chromium and its layout gate refuses the file when any page would
     * have to shrink below 0.85 — so a completed PDF export of a report carrying the comparison pages is the fit proof.
     */
    const exported = await request.post(`/api/v1/projects/${projectId}/reports/${report!.id}/export`, { headers, data: { format: 'pdf' } })
    expect(exported.ok(), `export returned ${exported.status()}`).toBe(true)
    let lastSeen = 'no export row'
    await expect.poll(async () => {
      const exports = ((await (await request.get(`/api/v1/projects/${projectId}/reports/${report!.id}`, { headers: API_HEADERS })).json()).data?.exports ?? []) as Array<{ format: string; status: string; error?: string | null }>
      const pdf = exports.filter((e) => e.format === 'pdf')
      if (pdf.length > 0) lastSeen = pdf.map((e) => `${e.status}${e.error ? `: ${e.error}` : ''}`).join(' | ')
      return pdf.some((e) => e.status === 'completed')
    }, { message: () => `the PDF export did not complete — ${lastSeen}`, timeout: 150_000, intervals: [3000] }).toBe(true)
    expect(lastSeen).not.toContain('layout_validation_failed')

    const created = await request.post(`/api/v1/projects/${projectId}/reports/${report!.id}/shares`, { headers, data: { mode: 'snapshot' } })
    expect(created.ok(), `share creation returned ${created.status()}`).toBe(true)
    const body = (await created.json()).data as Record<string, string>
    const raw = body.raw_token ?? body.token ?? body.url?.split('/r/')[1]
    expect(raw).toBeTruthy()

    await page.context().clearCookies()
    await page.goto(`/r/${raw}`)
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await page.getByRole('button', { name: 'صفحة', exact: true }).click()
    const slide = page.getByTestId('slide-format-comparison')
    await expect(slide.first()).toBeVisible({ timeout: 30000 })
    // ONE page per report, whatever the objectives (REPORT-FORMAT-COMPARE-COMPACT-001).
    await expect(slide).toHaveCount(1)
    await expect(slide.getByTestId('report-format-strip')).toBeVisible()
  })
})
