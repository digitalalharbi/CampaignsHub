import { expect, test, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject } from './helpers'

/**
 * GA4-PRINT-FIT-001 — the printed deck's GA4 pages hold the readable floor.
 *
 * A page that needs more than the floor (fit scale 0.85) fails the layout gate and BLOCKS every export of the report,
 * so every GA4 page type is measured on the page Chromium prints (`/reports/print/{token}?type=presentation`), with the
 * report's full data and with the sales elements hidden (marketing only). Long tables must be SPLIT across pages, never
 * shrunk: the number of pages per table is asserted, not only the scale.
 *
 * The deck prints in Arabic only (as every deck page does); the document layout carries no GA4 parts.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'
const FLOOR = 0.85
const SALES = ['tile.transactions', 'tile.revenue', 'tile.aov', 'tile.conversion_rate', 'chart.series.sales', 'stage.purchase', 'table.products', 'col.purchases', 'col.revenue', 'col.aov', 'col.conversion_rate', 'attr.sales', 'attr.gap', 'gap.headline', 'gap.reason.no_trace', 'gap.reason.attribution_rule', 'gap.reason.untagged_channel', 'gap.reason.no_platform_trace']

test.use({ storageState: AUTH.owner })

type Layout = { page: number; slideType: string; fitScale: number; overflow: boolean; overflowX: boolean; clippedElements: number; scaledBelowReadableLimit: boolean }

async function measure(page: Page, projectId: string, reportId: string, hidden: string[]): Promise<Layout[]> {
  const headers = { ...(await csrfHeaders(page.request)), 'Content-Type': 'application/json' }
  const put = await page.request.put(`/api/v1/projects/${projectId}/reports/${reportId}/sections`, { headers, data: { ga4_hidden: hidden } })
  expect(put.ok(), `sections ${put.status()} ${await put.text()}`).toBe(true)
  // Internal audience: the reader who sees the campaign tables, i.e. the longest pages.
  const re = await page.request.post(`/api/v1/projects/${projectId}/reports/${reportId}/regenerate`, { headers, data: { confirm: true } })
  expect(re.ok(), `regenerate ${re.status()}`).toBe(true)
  await expect.poll(async () => {
    const d = (await (await page.request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data
    return d?.status === 'completed' && d?.data?.ga4_attribution !== undefined
  }, { timeout: 120_000, intervals: [1500] }).toBe(true)

  const tok = await page.request.post(`/api/v1/projects/${projectId}/reports/${reportId}/print-token`, { headers, data: { type: 'presentation' } })
  expect(tok.status(), await tok.text()).toBe(200)
  await page.goto(`/reports/print/${(await tok.json()).data.token}?type=presentation`)
  await page.waitForFunction(() => (window as unknown as { __REPORT_LAYOUT__?: unknown }).__REPORT_LAYOUT__ !== undefined, null, { timeout: 90_000 })

  return (await page.evaluate(() => (window as unknown as { __REPORT_LAYOUT__: Layout[] }).__REPORT_LAYOUT__)) as Layout[]
}

for (const [mode, hidden] of [['full data', [] as string[]], ['marketing only', SALES]] as const) {
  test(`every GA4 print page holds the readable floor, ${mode}, and long tables split instead of shrinking`, async ({ page, request }) => {
    test.setTimeout(240_000)
    const projectId = await seededProject(request, STORE_PROJECT)
    const list = ((await (await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status?: string }>
    const report = list.find((r) => r.status === 'completed')
    expect(report, 'the store project has no completed report').toBeTruthy()
    await page.request.put(`/api/v1/projects/${projectId}/reports/${report!.id}`, { headers: { ...(await csrfHeaders(request)), 'Content-Type': 'application/json' }, data: { audience: 'internal' } }).catch(() => undefined)

    const pages = await measure(page, projectId, report!.id, [...hidden])
    const ga4 = pages.filter((p) => p.slideType.startsWith('__ga4_'))
    // eslint-disable-next-line no-console
    console.log(`GA4-PRINT-FIT ${mode}: ` + ga4.map((p) => `${p.slideType}=${p.fitScale}`).join(' '))

    const types = new Set(ga4.map((p) => p.slideType))
    for (const t of mode === 'full data' ? ['__ga4_pulse', '__ga4_attribution', '__ga4_reasons', '__ga4_classification', '__ga4_products'] : ['__ga4_pulse', '__ga4_attribution', '__ga4_classification']) {
      expect(types.has(t), `no ${t} page was printed (${[...types].join(',')})`).toBe(true)
    }
    for (const p of ga4) {
      expect(p.fitScale, `${p.slideType} printed at ${p.fitScale}, below the readable floor`).toBeGreaterThanOrEqual(FLOOR)
      expect(p.scaledBelowReadableLimit, p.slideType).toBe(false)
      expect(p.overflow, `${p.slideType} overflows its page`).toBe(false)
      expect(p.clippedElements, `${p.slideType} clips content`).toBe(0)
    }
    if (mode === 'full data') {
      expect(ga4.filter((p) => p.slideType === '__ga4_classification').length, 'classification rows were cut, not split').toBeGreaterThan(1)
    }
  })
}
