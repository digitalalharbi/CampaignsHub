import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CAMPAIGN-INTELLIGENCE-HUB — the campaigns landing opened from somebody else's link reads the window that link names.
 *
 * A shared link is an address opened cold. The landing must keep the period it was given (not reset it to the default) and
 * ask the server for that window — the same window Analytics reads, which CampaignsLandingAnalyticsParityTest holds equal.
 */
const STORE_PROJECT = 'متجر تجريبي — Demo'

test.describe('the campaigns landing deep link', () => {
  test.use({ storageState: AUTH.owner })

  test('a cold link keeps its window and asks the server for exactly that window', async ({ page, request }) => {
    test.setTimeout(120_000)
    await selectProject(page, await seededProject(request, STORE_PROJECT))

    // The landing's own address names its window as «the last N days» (`?days=`), the period control's unit.
    const days = 14
    const asked = page.waitForRequest((r) => /\/metrics\/campaigns\b/.test(r.url()), { timeout: 30000 })
    await page.goto(`/agency/campaigns?days=${days}`)
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })

    const req = new URL((await asked).url())
    const from = Date.parse(req.searchParams.get('from') ?? '')
    const to = Date.parse(req.searchParams.get('to') ?? '')
    // Fourteen days inclusive — not the 30-day default a page that ignored the link would ask for.
    expect(Math.round((to - from) / 86_400_000) + 1).toBe(days)
    await expect.poll(async () => new URL(page.url()).searchParams.get('days'), { timeout: 20000 }).toBe(String(days))

    // The same window reaches the strip Analytics' totals come from.
    const strip = page.waitForRequest((r) => /\/metrics\/summary\b/.test(r.url()) && r.url().includes(`from=${req.searchParams.get('from')}`), { timeout: 30000 })
    await page.reload()
    await expect(page.locator('main')).toBeVisible({ timeout: 30000 })
    expect(new URL((await strip).url()).searchParams.get('to')).toBe(req.searchParams.get('to'))
  })
})
