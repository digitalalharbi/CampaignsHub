import { writeFileSync } from 'node:fs'
import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, seededProject } from './helpers'

// Untracked evidence helper (REPORT-FORMAT-COMPARE-COMPACT-001): never committed.
const DIR = process.env.SHOTS_DIR as string
const TAG = process.env.SHOTS_TAG as string
const BLOCK = '[data-testid="report-format-comparison"], [data-testid="report-format-strip"]'
const SLIDE = '[data-testid="slide-format-comparison"], [data-testid="slide-format-table"], [data-testid="slide-format-strip"]'

test.use({ storageState: AUTH.owner })

test('rep shots', async ({ page, request }) => {
  test.setTimeout(900_000)
  const facts: Record<string, unknown> = {}
  const projectId = await seededProject(request, 'متجر تجريبي — Demo')
  const headers = { ...(await csrfHeaders(request)), 'Content-Type': 'application/json' }
  const list = ((await (await request.get(`/api/v1/projects/${projectId}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status?: string }>
  const report = list.find((r) => r.status === 'completed')!
  await request.post(`/api/v1/projects/${projectId}/reports/${report.id}/regenerate`, { headers, data: { confirm: true } })
  await expect.poll(async () => ((await (await request.get(`/api/v1/projects/${projectId}/reports/${report.id}`, { headers: API_HEADERS })).json()).data?.status), { timeout: 180_000 }).toBe('completed')
  const created = await request.post(`/api/v1/projects/${projectId}/reports/${report.id}/shares`, { headers, data: { mode: 'snapshot' } })
  const body = (await created.json()).data as Record<string, string>
  const raw = body.raw_token ?? body.token ?? body.url?.split('/r/')[1]

  await page.context().clearCookies()
  for (const [w, h, n] of [[1440, 900, 'desktop'], [375, 812, '375']] as const) {
    await page.setViewportSize({ width: w, height: h })
    await page.goto('/r/demo-live-report-token?view=content')
    const block = page.locator(BLOCK).first()
    await expect(block).toBeVisible({ timeout: 60000 })
    await block.scrollIntoViewIfNeeded()
    await page.waitForTimeout(800)
    await block.screenshot({ path: `${DIR}/rep-${TAG}-live-${n}.png` })
    const box = await block.boundingBox()
    facts[`live-${n}`] = { height: box?.height, width: box?.width, overflowX: await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth) }

    await page.goto(`/r/${raw}`)
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    await page.getByRole('button', { name: 'صفحة', exact: true }).click()
    const slides = page.locator(SLIDE)
    await expect(slides.first()).toBeVisible({ timeout: 60000 })
    await slides.first().scrollIntoViewIfNeeded()
    await page.waitForTimeout(800)
    await slides.first().screenshot({ path: `${DIR}/rep-${TAG}-saved-${n}.png` })
    const sbox = await slides.first().boundingBox()
    facts[`saved-${n}`] = { slides: await slides.count(), height: sbox?.height, overflowX: await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth) }
  }
  writeFileSync(`${DIR}/rep-${TAG}-facts.json`, JSON.stringify(facts, null, 2))
})
