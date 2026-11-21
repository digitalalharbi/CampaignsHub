import { execFileSync } from 'node:child_process'
import { join } from 'node:path'
import { expect, test, type Page } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject } from './helpers'
import { E2E_BACKEND_ENV } from './env'

/**
 * REPORT-WEBSITE-TAB-001 — a saved snapshot renders as it was frozen («أي تغيير في Snapshot محفوظ يجب أن يحافظ على النسخة التاريخية»).
 *
 * A snapshot saved before the website tab carries no `website_layout` stamp: its store-pulse page must still print, inside the
 * readable floor, and its PDF must still export. A new snapshot (stamped) prints no pulse page. The old snapshot is made the only
 * honest way available to a browser test: a fresh snapshot with the stamp taken back out of its stored data, which is exactly the
 * shape every snapshot saved before the stamp existed has.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'
const FLOOR = 0.85
// The seeded report whose period (the last 30 days) holds the demo store's GA4 data. Not «the newest completed report»: specs that run
// before this one (report-form-composition) save June-2026 reports, newer than the seeded one, whose period has no GA4 rows — their
// `ga4_pulse` is honestly null, so the poll below could never turn true.
const SEEDED_REPORT = 'تقرير المنصات — مقارنة'

test.use({ storageState: AUTH.owner })

type Layout = { page: number; slideType: string; fitScale: number; overflow: boolean; clippedElements: number; scaledBelowReadableLimit: boolean }

async function layout(page: Page, projectId: string, reportId: string): Promise<Layout[]> {
  const headers = { ...(await csrfHeaders(page.request)), 'Content-Type': 'application/json' }
  const tok = await page.request.post(`/api/v1/projects/${projectId}/reports/${reportId}/print-token`, { headers, data: { type: 'presentation' } })
  expect(tok.status(), await tok.text()).toBe(200)
  await page.goto(`/reports/print/${(await tok.json()).data.token}?type=presentation`)
  await page.waitForFunction(() => (window as unknown as { __REPORT_LAYOUT__?: unknown }).__REPORT_LAYOUT__ !== undefined, null, { timeout: 90_000 })

  return (await page.evaluate(() => (window as unknown as { __REPORT_LAYOUT__: Layout[] }).__REPORT_LAYOUT__)) as Layout[]
}

test('a snapshot frozen before the website tab keeps its pulse page, inside the floor, and still exports; a new one prints none', async ({ page, request }) => {
  test.setTimeout(360_000)
  const projectId = await seededProject(request, STORE_PROJECT)
  const list = ((await (await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; name?: string; status?: string }>
  const reportId = list.find((r) => r.status === 'completed' && r.name === SEEDED_REPORT)!.id
  const headers = { ...(await csrfHeaders(request)), 'Content-Type': 'application/json' }
  await request.put(`/api/v1/projects/${projectId}/reports/${reportId}/sections`, { headers, data: { ga4_hidden: [] } })
  expect((await request.post(`/api/v1/projects/${projectId}/reports/${reportId}/regenerate`, { headers, data: { confirm: true } })).ok()).toBe(true)
  await expect.poll(async () => {
    const d = (await (await request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data
    return d?.status === 'completed' && d?.data?.website_layout === 1 && d?.data?.ga4_pulse != null
  }, { timeout: 120_000, intervals: [1500] }).toBe(true)

  // A new snapshot: no pulse page.
  const fresh = await layout(page, projectId, reportId)
  expect(fresh.some((p) => p.slideType === '__ga4_pulse'), 'a new snapshot printed a pulse page').toBe(false)

  // The same snapshot as every pre-website-tab snapshot stored it: no stamp.
  execFileSync('php', ['artisan', 'tinker', `--execute=$r = App\\Domains\\Reports\\Models\\Report::withoutGlobalScopes()->find('${reportId}'); $d = $r->data; unset($d['website_layout']); $r->forceFill(['data' => $d])->saveQuietly(); echo 'ok';`], {
    cwd: join(process.cwd(), '..', 'backend'), env: { ...process.env, ...E2E_BACKEND_ENV }, encoding: 'utf8',
  })

  const frozen = await layout(page, projectId, reportId)
  const pulse = frozen.filter((p) => p.slideType === '__ga4_pulse')
  // eslint-disable-next-line no-console
  console.log(`FROZEN-PULSE fit: ${pulse.map((p) => p.fitScale).join(',')}`)
  expect(pulse, 'the frozen snapshot lost its pulse page').toHaveLength(1)
  for (const p of pulse) {
    expect(p.fitScale, `the pulse page printed at ${p.fitScale}`).toBeGreaterThanOrEqual(FLOOR)
    expect(p.overflow).toBe(false)
    expect(p.clippedElements).toBe(0)
  }

  const queued = await request.post(`/api/v1/projects/${projectId}/reports/${reportId}/export`, { headers, data: { format: 'pdf' } })
  expect(queued.status()).toBe(202)
  const exportId = (await queued.json()).data.id as string
  await expect.poll(async () => {
    const exports = ((await (await request.get(`/api/v1/projects/${projectId}/reports/${reportId}`, { headers: API_HEADERS })).json()).data.exports ?? []) as Array<{ id: string; status: string }>
    return exports.find((e) => e.id === exportId)?.status
  }, { timeout: 150_000, intervals: [2000] }).toBe('completed')
})
