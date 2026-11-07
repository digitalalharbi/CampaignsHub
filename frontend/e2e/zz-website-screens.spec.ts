import { test } from '@playwright/test'

// Evidence tooling, not part of the suite: screenshots of the live link for the website-tab report.
const TOKEN = 'demo-live-report-token'
const OUT = process.env.SHOT_DIR ?? '/tmp/shots'

test.use({ storageState: { cookies: [], origins: [] } })
test.describe.configure({ mode: 'serial' })

for (const [width, height] of [[1440, 1000], [375, 812]] as const) {
  for (const theme of ['dark', 'light'] as const) {
    test(`shots ${width} ${theme}`, async ({ page }) => {
      test.setTimeout(240_000)
      await page.addInitScript(([l, t]) => {
        window.localStorage.setItem('campaign-hub-locale', l)
        window.localStorage.setItem('campaign-hub-theme', t)
      }, ['ar', theme] as const)
      await page.setViewportSize({ width, height })
      for (const view of ['summary', 'dashboard', 'website', 'tables']) {
        await page.goto(`/r/${TOKEN}?view=${view}`)
        await page.getByTestId('live-report').waitFor({ timeout: 40_000 })
        await page.waitForLoadState('networkidle')
        if (view === 'tables') await page.getByTestId('daily-metric-clicks').click().catch(() => undefined)
        await page.screenshot({ path: `${OUT}/live-${view}-${width}-${theme}.png`, fullPage: true })
      }
    })
  }
}
