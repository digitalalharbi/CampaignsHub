import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH } from './helpers'

/**
 * OBJECTIVE-RESULT-001 — each objective path carries its OWN result, named, through the running stack.
 *
 * Every path read its orders, so a traffic path of visits printed «0». The figures behind the
 * report's «كل هدف» table come from `ObjectivePerformance`, read here through the same endpoint the
 * operator's report and Analytics use. (A CLIENT link carries that block only under «التقسيم المتقدم»
 * with business streams set, which the seed does not have; the table itself is held by
 * `liveDetailObjectiveResult.test.tsx`.)
 */
test.use({ storageState: AUTH.owner })

test('every path that spent states its own result and the words for it', async ({ page }) => {
  type Path = { path: string; spend: number; result_key?: string | null; result?: number | null; result_label?: { ar: string; en: string } | null }
  const to = new Date().toISOString().slice(0, 10)
  const from = new Date(Date.now() - 29 * 86_400_000).toISOString().slice(0, 10)

  const projects = (await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json()).data as Array<{ id: string }>
  const spent: Path[] = []
  for (const p of projects) {
    const res = await page.request.get(`/api/v1/projects/${p.id}/metrics/objective-performance?from=${from}&to=${to}`, { headers: API_HEADERS })
    if (!res.ok()) continue
    spent.push(...(((await res.json()).data?.paths ?? []) as Path[]).filter((x) => x.spend > 0))
  }
  expect(spent.length, 'no path spent in the seeded window, so this proves nothing').toBeGreaterThan(0)

  for (const p of spent) {
    expect(p.result_key, `${p.path} has no result key`).toBeTruthy()
    expect(p.result_label?.ar, `${p.path} has no result words`).toBeTruthy()
    if (p.path === 'traffic') expect(['landing_page_views', 'clicks']).toContain(p.result_key)
    if (p.path === 'awareness') expect(p.result_key).toBe('impressions')
    if (p.path === 'conversion') expect(p.result_key).toBe('conversions')
  }
})
