import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

const STORE_PROJECT = 'متجر تجريبي — Demo'

/**
 * DATE-RANGE-001 — one period for the whole system, on a held clock (Sunday 15 November 2026, 09:00 Riyadh).
 *
 * «آخر 30 يوم» must be 16 October → 14 November — the window the ad platforms use — and the period must
 * survive moving between pages, a reload, and a link. At 1366 the panel is a popover with two months; at 390
 * it is a bottom sheet with one, and every control is a thumb's size.
 */
const NOW = new Date('2026-11-15T06:00:00Z')

test.describe('the period picker', () => {
  test.use({ storageState: AUTH.owner })

  test.beforeEach(async ({ page }) => {
    await page.clock.install({ time: NOW })
  })

  test('defaults to the last 7 complete days, ends yesterday and carries itself in the address', async ({ page }) => {
    await page.goto('/agency/analytics')
    await expect(page.getByTestId('analytics-period-summary')).toContainText('آخر 7 أيام: 8 نوفمبر – 14 نوفمبر 2026', { timeout: 30_000 })
    await expect.poll(() => new URL(page.url()).searchParams.get('from')).toBe('2026-11-08')
    expect(new URL(page.url()).searchParams.get('to')).toBe('2026-11-14')
    await expect(page.getByTestId('analytics-period-budget-hint')).toContainText('الميزانية تُحسب على فترتها')
  })

  test('«آخر 30 يوم» is 16 Oct → 14 Nov, compares with 16 Sep → 15 Oct, and survives navigation and reload', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics')
    await page.getByTestId('analytics-period-button').click()
    const panel = page.getByTestId('analytics-period-panel')
    await expect(panel).toBeVisible()
    await expect(panel).toContainText('التواريخ بتوقيت الرياض (GMT+3)')
    // PICKER-SHORT-PRESETS-001 — «آخر 30 يوم» lives under «تخصيص».
    await page.getByTestId('analytics-period-preset-custom').click()
    await page.getByTestId('analytics-period-preset-last_30').click()
    await page.getByTestId('analytics-period-compare-toggle').check()
    await expect(page.getByTestId('analytics-period-compare-range')).toContainText('16 سبتمبر – 15 أكتوبر 2026')
    await page.getByTestId('analytics-period-apply').click()

    await expect(page.getByTestId('analytics-period-summary')).toContainText('آخر 30 يوم: 16 أكتوبر – 14 نوفمبر 2026')
    const url = new URL(page.url())
    expect([url.searchParams.get('from'), url.searchParams.get('to'), url.searchParams.get('compare')]).toEqual(['2026-10-16', '2026-11-14', 'previous'])

    // Another page, entered by a bare address: the period is the user's, not the page's.
    await page.goto('/agency/campaigns')
    await expect(page.getByTestId('ads-manager-period-summary')).toContainText('آخر 30 يوم: 16 أكتوبر – 14 نوفمبر 2026', { timeout: 30_000 })
    await expect.poll(() => new URL(page.url()).searchParams.get('from')).toBe('2026-10-16')

    await page.reload()
    await expect(page.getByTestId('ads-manager-period-summary')).toContainText('16 أكتوبر – 14 نوفمبر 2026', { timeout: 30_000 })
  })

  test('opens a shared link on the same two dates; «هذا الشهر» carries no «in progress» badge', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/analytics?from=2026-09-03&to=2026-09-09')
    await expect(page.getByTestId('dashboard-period-summary').or(page.getByTestId('analytics-period-summary'))).toContainText('3 سبتمبر – 9 سبتمبر 2026', { timeout: 30_000 })

    await page.goto('/agency/campaigns?from=2026-11-01&to=2026-11-15')
    await expect(page.getByTestId('ads-manager-period-summary')).toContainText('1 نوفمبر – 15 نوفمبر 2026', { timeout: 30_000 })
    await expect(page.getByTestId('ads-manager-period-status-ongoing')).toHaveCount(0)
  })

  test('picks a range with two clicks and refuses a day that has not come', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.goto('/agency/campaigns')
    await page.getByTestId('ads-manager-period-button').click()
    await page.getByTestId('day-2026-11-03').click()
    await page.getByTestId('day-2026-11-09').click()
    await expect(page.getByTestId('day-2026-11-05')).toHaveAttribute('data-state', 'period')
    await expect(page.getByTestId('day-2026-11-16')).toBeDisabled()
    await page.getByTestId('ads-manager-period-apply').click()
    await expect(page.getByTestId('ads-manager-period-summary')).toContainText('3 نوفمبر – 9 نوفمبر 2026')
  })

  test('on a phone it is a bottom sheet with one month, scrolling presets, and thumb-sized controls', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/agency/campaigns')
    await page.getByTestId('ads-manager-period-button').click()
    const sheet = page.getByTestId('ads-manager-period-sheet')
    await expect(sheet).toBeVisible()
    await expect(sheet.locator('[data-testid^="ads-manager-period-cal-"]')).toHaveCount(1)

    const presets = page.getByTestId('ads-manager-period-presets')
    expect(await presets.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true)

    const box = await page.getByTestId('day-2026-11-10').boundingBox()
    expect(box!.height).toBeGreaterThanOrEqual(44)
    for (const id of ['ads-manager-period-apply', 'ads-manager-period-cancel', 'ads-manager-period-next']) {
      const b = await page.getByTestId(id).boundingBox()
      expect(b!.height, id).toBeGreaterThanOrEqual(43.5)
    }
    for (const id of ['ads-manager-period-from', 'ads-manager-period-to']) {
      expect(await page.getByTestId(id).evaluate((el) => parseFloat(getComputedStyle(el).fontSize)), id).toBeGreaterThanOrEqual(16)
    }
    // No sideways scroll on the page itself.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true)
  })

  // PICKER-ONE-MONTH-001 — one month on every screen (Meta / Snapchat style), and every control inside the screen.
  test('on a desktop it shows one month, with Update and Cancel in view', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, STORE_PROJECT))
    await page.setViewportSize({ width: 1366, height: 800 })
    await page.goto('/agency/campaigns')
    await page.getByTestId('ads-manager-period-button').click()
    await expect(page.getByTestId('ads-manager-period-panel').locator('[data-testid^="ads-manager-period-cal-"]')).toHaveCount(1)
    await expect(page.getByTestId('ads-manager-period-apply')).toBeInViewport()
    await expect(page.getByTestId('ads-manager-period-cancel')).toBeInViewport()
  })
})
