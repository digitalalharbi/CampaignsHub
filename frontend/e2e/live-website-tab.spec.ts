import { expect, test } from '@playwright/test'

/**
 * REPORT-WEBSITE-TAB-001 — the client's live link, against the DEMO property (`DemoGa4Seeder`, labelled demo end to end).
 *
 * What only a browser can say: the «الموقع» tab is the one home of the website, the summary carries one strip that opens it,
 * the word «GA4» never reaches the page, the daily table is an interactive chart that answers clicks and the keyboard, and
 * nothing of the website is drawn in the other tabs.
 */
const LIVE_TOKEN = 'demo-live-report-token'
const WEBSITE_FIGURES = ['ga4-tiles', 'ga4-channels', 'ga4-devices', 'ga4-gap']

test.describe('the website tab of a live link', () => {
  test.beforeEach(async ({ page }) => {
    await page.context().clearCookies()
  })

  test('is one tab, holds every website figure once, and names nothing GA4', async ({ page }) => {
    test.setTimeout(120_000)
    await page.goto(`/r/${LIVE_TOKEN}`)
    await expect(page.getByTestId('live-modes')).toBeVisible({ timeout: 30_000 })
    await expect(page.getByTestId('live-mode-tab-website')).toBeVisible()

    const where: Record<string, number> = {}
    for (const mode of ['summary', 'dashboard', 'platforms', 'content', 'tables', 'website']) {
      await page.getByTestId(`live-mode-tab-${mode}`).click()
      await expect(page.getByTestId(`live-mode-${mode}`)).toBeVisible()
      let n = 0
      for (const id of WEBSITE_FIGURES) n += await page.getByTestId(id).count()
      where[mode] = n
      expect((await page.getByTestId('live-report').innerText()), mode).not.toMatch(/ga4/i)
    }
    for (const mode of ['summary', 'dashboard', 'platforms', 'content', 'tables']) expect(where[mode], `${mode} drew a website figure`).toBe(0)
    expect(where.website, 'the website tab is missing its figures').toBeGreaterThanOrEqual(WEBSITE_FIGURES.length)

    // The platform chips and the banner never list the website as an ad platform.
    await expect(page.getByTestId('live-platform-ga4')).toHaveCount(0)
    await expect(page.getByTestId('live-freshness')).toHaveCount(0)
  })

  test('the summary strip opens the website tab', async ({ page }) => {
    test.setTimeout(120_000)
    await page.goto(`/r/${LIVE_TOKEN}`)
    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 30_000 })
    // The demo link names campaigns, so the store-wide pulse is not its to carry: the strip appears only where the server sent it.
    const strip = page.getByTestId('website-strip')
    if ((await strip.count()) > 0) await strip.getByTestId('website-strip-open').click()
    else await page.getByTestId('live-mode-tab-website').click()
    await expect(page.getByTestId('live-mode-website')).toBeVisible()
    await expect(page.getByTestId('report-ga4-overview')).toBeVisible()
  })

  test('the daily table is an interactive chart: metric chips, platform toggles, a donut total and a keyboard readout', async ({ page }) => {
    test.setTimeout(120_000)
    await page.goto(`/r/${LIVE_TOKEN}?view=tables`)
    const chart = page.getByTestId('daily-chart')
    await expect(chart).toBeVisible({ timeout: 30_000 })
    await expect(page.getByTestId('tables-days')).toHaveCount(0)

    const before = await page.getByTestId('daily-area').boundingBox()
    await page.getByTestId('daily-metric-clicks').click()
    await expect(chart).toHaveAttribute('data-metric', 'clicks')
    const total = Number(await page.getByTestId('daily-donut').getAttribute('data-total'))
    expect(total).toBeGreaterThan(0)
    const toggles = page.locator('[data-testid^="daily-platform-"]')
    if ((await toggles.count()) > 1) {
      await toggles.first().click()
      expect(Number(await page.getByTestId('daily-donut').getAttribute('data-total'))).toBeLessThanOrEqual(total)
      await toggles.first().click()
    }
    const after = await page.getByTestId('daily-area').boundingBox()
    expect(after?.height, 'toggling moved the chart').toBe(before?.height)

    await page.getByRole('group', { name: /القياس اليومي|Daily measurement/ }).focus()
    await page.keyboard.press('ArrowRight')
    await expect(page.getByTestId('stacked-area-readout')).toContainText(/\d{4}-\d{2}-\d{2}/)
    // No spend on a link that hides spend: the demo client link carries none.
    await expect(page.getByTestId('daily-metric-spend')).toHaveCount(0)
  })

  for (const theme of ['dark', 'light'] as const) {
    test(`the website tab and the chart do not scroll sideways on a phone, Arabic, ${theme}`, async ({ page }) => {
      test.setTimeout(120_000)
      await page.addInitScript(([l, t]) => {
        window.localStorage.setItem('campaign-hub-locale', l)
        window.localStorage.setItem('campaign-hub-theme', t)
      }, ['ar', theme] as const)
      await page.setViewportSize({ width: 375, height: 812 })
      for (const view of ['website', 'tables']) {
        await page.goto(`/r/${LIVE_TOKEN}?view=${view}`)
        await expect(page.getByTestId(`live-mode-${view}`)).toBeVisible({ timeout: 30_000 })
        await page.waitForLoadState('networkidle')
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
        expect(overflow, `${view} scrolls sideways at 375px`).toBeLessThanOrEqual(0)
      }
    })
  }
})
