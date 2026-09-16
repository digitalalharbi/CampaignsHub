import { expect, test, type Page } from '@playwright/test'

/**
 * The live client link as four products — driven as a client drives them, with no session.
 *
 * Unit tests pin each composition against fixtures. What only a browser can say is that the modes are
 * reachable by address, that the platform → content drilldown actually lands, that a platform's own
 * view AGREES with the whole link about that platform on the real seeded metrics, and that no mode
 * scrolls a phone sideways.
 */
const TOKEN = 'demo-live-report-token'

const ready = (page: Page, testid: string) => expect(page.getByTestId(testid)).toBeVisible({ timeout: 30000 })

/** A figure's leading number as rendered — «39.8K SAR» → «39.8K» — so two cards can be compared as text. */
const figure = (text: string) => (text.match(/[\d.,]+[KMB]?/)?.[0] ?? '')

test.describe('the live client link, mode by mode', () => {
  test('opens on the dashboard and moves between modes by address', async ({ page }) => {
    await page.goto(`/r/${TOKEN}`)
    await ready(page, 'live-mode-dashboard')

    const tabs = page.getByTestId('live-modes').getByRole('tab')
    await expect(tabs).toHaveCount(4)

    await page.getByTestId('live-mode-tab-summary').click()
    await ready(page, 'live-mode-summary')
    await expect(page).toHaveURL(/view=summary/)
    // The summary is a shorter product, not the dashboard retitled: none of the dashboard's tables.
    await expect(page.getByTestId('live-platform-comparison')).toHaveCount(0)
    await expect(page.getByTestId('live-detail-tables')).toHaveCount(0)

    await page.goBack()
    await ready(page, 'live-mode-dashboard')
  })

  test('a platform’s own view agrees with the whole link about that platform', async ({ page }) => {
    await page.goto(`/r/${TOKEN}?view=platforms`)
    await ready(page, 'live-mode-platforms')

    const card = page.locator('[data-testid^="live-platform-card-"]').first()
    const platform = ((await card.getAttribute('data-testid')) ?? '').replace('live-platform-card-', '')
    expect(platform, 'no platform card rendered, so this proves nothing').not.toBe('')

    await card.click()
    const view = page.getByTestId(`live-platform-view-${platform}`)
    await expect(view).toBeVisible({ timeout: 30000 })

    const cardSpend = figure(await card.locator('.tnum').first().innerText())
    const viewSpend = figure(await view.getByTestId('live-kpis').locator('.tnum').first().innerText())
    expect(cardSpend, 'the platform card rendered no spend').not.toBe('')
    expect(viewSpend, `${platform}: the platform view disagrees with its own card`).toBe(cardSpend)

    await expect(view.getByTestId('live-platform-trend')).toBeVisible()
  })

  test('drills from a platform into its content and opens one piece with its trend', async ({ page }) => {
    await page.goto(`/r/${TOKEN}?view=platforms`)
    await ready(page, 'live-mode-platforms')

    await page.getByTestId('live-platform-goto-content').click()
    await ready(page, 'live-mode-content')
    await expect(page).toHaveURL(/view=content&platform=/)
    await expect(page.locator('[data-testid^="live-content-platform-"][aria-selected="true"]')).not.toHaveAttribute('data-testid', 'live-content-platform-all')

    const tile = page.getByTestId('live-content-all').getByTestId('live-content-tile').first()
    await expect(tile).toBeVisible({ timeout: 30000 })
    await tile.click()

    const trend = page.getByTestId('live-content-trend')
    await expect(trend).toBeVisible()
    await expect(trend).not.toHaveAttribute('data-state', 'pending', { timeout: 20000 })
    // A content key the page itself handed out must resolve: «failed» here is the drilldown broken.
    await expect(trend).toHaveAttribute('data-state', 'ready')
  })
})

test.describe('every mode holds together on a phone', () => {
  for (const locale of ['ar', 'en'] as const) {
    test(`375px · ${locale}: no mode scrolls sideways`, async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 812 })
      if (locale === 'en') await page.addInitScript(() => window.localStorage.setItem('campaign-hub-locale', 'en'))

      for (const [view, testid] of [
        ['summary', 'live-mode-summary'],
        ['dashboard', 'live-mode-dashboard'],
        ['platforms', 'live-mode-platforms'],
        ['content', 'live-mode-content'],
      ] as const) {
        await page.goto(`/r/${TOKEN}?view=${view}`)
        await ready(page, testid)
        await page.waitForTimeout(300)

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
        expect(overflow, `${view} scrolls ${overflow}px sideways at 375px in ${locale}`).toBeLessThanOrEqual(0)
      }
    })
  }
})
