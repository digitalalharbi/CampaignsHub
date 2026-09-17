import { expect, test, type Page } from '@playwright/test'
import { API_HEADERS, AUTH } from './helpers'

/**
 * REPORT-DRILLDOWN-001 — the optional deeper analysis, opened and closed as a reader does it.
 *
 * Unit tests pin the drawer against fixtures and the feature tests pin the endpoints. What only a
 * browser says is that a real comparison row on the seeded demo link opens a drawer that LOADS (a
 * «failed» state here is the endpoint broken against real data), that one of its content tiles opens
 * the content dialog, and that closing each lands the reader back where they were — for a client with
 * no session and for the operator viewing the same link signed in.
 */
const TOKEN = 'demo-live-report-token'

async function openAndCloseDrilldowns(page: Page) {
  await page.goto(`/r/${TOKEN}`)
  await expect(page.getByTestId('live-mode-dashboard')).toBeVisible({ timeout: 30000 })

  const open = page.locator('[data-testid^="live-platform-open-"]').first()
  await expect(open, 'the comparison offers no platform to open, so this proves nothing').toBeVisible({ timeout: 30000 })
  const provider = ((await open.getAttribute('data-testid')) ?? '').replace('live-platform-open-', '')
  await open.click()

  const drawer = page.getByTestId('live-platform-drawer')
  await expect(drawer).toBeVisible()
  await expect(drawer).toHaveAttribute('data-provider', provider)
  await expect(drawer).not.toHaveAttribute('data-state', 'pending', { timeout: 30000 })
  await expect(drawer).toHaveAttribute('data-state', 'ready')

  await expect(drawer.getByTestId('live-platform-drawer-shares')).toBeVisible()
  await expect(drawer.getByTestId('live-platform-drawer-trend')).toBeVisible()
  await expect(drawer.locator('[data-testid^="live-platform-drawer-kpis-"]').first()).toBeVisible()
  // The client path is platform → content: the drawer never names a campaign.
  await expect(drawer).not.toContainText(/campaign|حملة|الحملات/i)

  const tile = drawer.getByTestId('live-content-tile').first()
  if (await tile.count()) {
    await tile.click()
    const dialog = page.getByTestId('report-ad-detail')
    await expect(dialog).toBeVisible()
    // Clicked, not keyed: a dialog opened beneath the drawer would refuse the click.
    await dialog.getByTestId('report-ad-detail-close').click()
    await expect(dialog).toHaveCount(0)
    await expect(drawer, 'closing the content dialog closed the drawer behind it too').toBeVisible()
  }

  await drawer.getByTestId('live-platform-drawer-close').click()
  await expect(drawer).toHaveCount(0)
  await expect(page.getByTestId('live-mode-dashboard')).toBeVisible()

  // Reopen and leave with Escape: a drawer a keyboard cannot leave is a trap.
  await open.click()
  await expect(page.getByTestId('live-platform-drawer')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByTestId('live-platform-drawer')).toHaveCount(0)

  return provider
}

test.describe('drill-downs on the shared link, with no session', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('a platform opens from the comparison into a drawer and closes back to the dashboard', async ({ page }) => {
    await openAndCloseDrilldowns(page)
  })

  test('the shared link reaches no campaign-level data', async ({ request }) => {
    for (const path of ['live/campaign/x', 'live/campaigns', 'live/platform/meta/campaigns']) {
      const res = await request.get(`/api/v1/reports/shared/${TOKEN}/${path}`, { headers: API_HEADERS })
      expect(res.status(), path).toBe(404)
    }

    const live = (await (await request.get(`/api/v1/reports/shared/${TOKEN}/live`, { headers: API_HEADERS })).json()).data
    const provider = String(live.platforms?.[0]?.provider ?? '')
    expect(provider, 'the demo link has no platform, so this proves nothing').not.toBe('')

    const res = await request.get(`/api/v1/reports/shared/${TOKEN}/live/platform/${provider}`, { headers: API_HEADERS })
    expect(res.status()).toBe(200)
    const body = JSON.stringify(await res.json())
    for (const key of ['"campaign_name"', '"campaign_id"', '"ad_set_id"', '"ad_set_name"', '"campaigns"']) {
      expect(body, `the platform drill-down carries ${key}`).not.toContain(key)
    }
  })
})

test.describe('drill-downs on Live, for the signed-in operator', () => {
  test.use({ storageState: AUTH.owner })

  test('the operator opens and closes the same drill-downs on the live link', async ({ page }) => {
    await openAndCloseDrilldowns(page)
  })

  test('the operator’s drill-down answers inside the project and nowhere else', async ({ page }) => {
    const projects = (await (await page.request.get('/api/v1/projects', { headers: API_HEADERS })).json()).data as Array<{ id: string }>

    let found: { project: string; report: string; share: string } | null = null
    for (const project of projects) {
      const reports = ((await (await page.request.get(`/api/v1/projects/${project.id}/reports`, { headers: API_HEADERS })).json()).data?.reports ?? []) as Array<{ id: string; status: string }>
      for (const report of reports.filter((r) => r.status === 'completed')) {
        const shares = ((await (await page.request.get(`/api/v1/projects/${project.id}/reports/${report.id}/shares`, { headers: API_HEADERS })).json()).data ?? []) as Array<{ id: string; mode: string; active: boolean }>
        const live = shares.find((s) => s.mode === 'live' && s.active)
        if (live) {
          found = { project: project.id, report: report.id, share: live.id }
          break
        }
      }
      if (found) break
    }
    expect(found, 'the operator has no live link to drill into, so this proves nothing').not.toBeNull()
    const { project, report, share } = found!

    const base = `/api/v1/projects/${project}/reports/${report}/shares`
    const live = (await (await page.request.get(`/api/v1/reports/shared/${TOKEN}/live`, { headers: API_HEADERS })).json()).data
    const provider = String(live.platforms?.[0]?.provider ?? 'meta')

    const ok = await page.request.get(`${base}/${share}/live/platform/${provider}`, { headers: API_HEADERS })
    expect([200, 404], 'the operator route errored rather than answering').toContain(ok.status())
    if (ok.status() === 200) {
      expect(JSON.stringify(await ok.json())).not.toContain('"campaign_name"')
    }

    // A share id that is not this report's resolves to nothing.
    const stranger = await page.request.get(`${base}/00000000-0000-4000-8000-000000000000/live/platform/${provider}`, { headers: API_HEADERS })
    expect(stranger.status()).toBe(404)
  })
})
