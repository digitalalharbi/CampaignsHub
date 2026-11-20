import { execFileSync } from 'node:child_process'
import { expect, test, type Page } from '@playwright/test'
import { AUTH, csrfHeaders } from './helpers'
import { E2E_BACKEND_ENV } from './env'

/**
 * NOTIF-BELL-001 / NOTIF-SOUND-001 — the bell, in a real browser, on rows the real dispatcher raised.
 *
 * `e2e:notify` raises team-wide notifications on the demo agency's first project (what an alert sweep does). Nothing here is
 * mocked: the badge, the filters, «تعليم الكل كمقروء» and the ping all run against the served app and the e2e database.
 */
test.use({ storageState: AUTH.owner, locale: 'ar-SA' })

const OWNER = 'agency@campaignshub.io'

function raise(args: string[]): number {
  const out = execFileSync('php', ['artisan', 'e2e:notify', OWNER, ...args], {
    cwd: new URL('../../backend', import.meta.url).pathname,
    env: { ...process.env, ...E2E_BACKEND_ENV },
    encoding: 'utf-8',
  }).trim().split('\n').at(-1) as string

  return Number(out)
}

/** Count the ping: every oscillator the page starts. A fresh array per page load. */
async function watchAudio(page: Page) {
  await page.addInitScript(() => {
    const w = window as unknown as { __pings: number[]; AudioContext: typeof AudioContext }
    w.__pings = []
    const Base = w.AudioContext
    if (!Base) return
    w.AudioContext = class extends Base {
      createOscillator() {
        const osc = super.createOscillator()
        const stop = osc.stop.bind(osc)
        osc.stop = (when?: number) => { w.__pings.push(when ?? 0); stop(when) }

        return osc
      }
    } as typeof AudioContext
  })
}

const pings = (page: Page) => page.evaluate(() => (window as unknown as { __pings: number[] }).__pings.length)

/** Ask the bell to look again now (a tab regaining focus does exactly this). */
const lookAgain = (page: Page) => page.evaluate(() => { document.dispatchEvent(new Event('visibilitychange', { bubbles: true })) })

const bell = (page: Page) => page.getByRole('button', { name: 'الإشعارات' })

test.describe('the bell', () => {
  test.beforeEach(() => { raise(['--purge', '--count=0']) })

  test('names a finding by what it is, filters, opens the object, and clears everything in one call', async ({ page }) => {
    // The 110 come first: the panel lists the newest 100, so the three findings below must be newer than the bulk.
    raise(['--count=110', '--kind=cost_up', '--type=budget_risk', '--severity=warning'])
    // The list is newest first at second precision (`created_at`), capped at 100 rows: the single «no_results» must be
    // NEWER than the 110, not raised in the same second (a tie there is in no particular order and may fall past row 100).
    await new Promise((r) => setTimeout(r, 1100))
    raise(['--count=1', '--kind=no_results', '--type=budget_risk', '--severity=warning'])
    raise(['--count=1', '--kind=budget_90', '--type=budget_risk', '--severity=warning'])
    raise(['--count=1', '--kind=sync_failed', '--type=sync_failed', '--severity=critical'])

    await page.goto('/agency/dashboard')
    // The number is the real count — 113 — and reads 99+ above 99.
    await expect(page.getByTestId('bell-badge')).toHaveText('99+', { timeout: 20_000 })

    await bell(page).click()
    const panel = page.getByTestId('bell-filter-tabs')
    for (const label of ['الكل', 'حرج', 'ميزانية', 'أداء', 'مزامنة']) await expect(panel.getByRole('tab', { name: label })).toBeVisible()

    await page.getByTestId('bell-filter-performance').click()
    await expect(page.locator('[data-kind="no_results"] [data-testid="notification-title"]').first()).toHaveText('صرف بدون نتائج')
    // No row anywhere is titled «تجاوز حدود الميزانية»: nothing here is over its budget.
    await expect(page.getByText('تجاوز حدود الميزانية')).toHaveCount(0)

    await page.getByTestId('bell-filter-budget').click()
    const row = page.locator('[data-kind="budget_90"]')
    await expect(row.locator('[data-testid="notification-title"]')).toHaveText('الميزانية 90%')

    await page.getByTestId('bell-filter-critical').click()
    await expect(page.locator('[data-kind="sync_failed"] [data-testid="notification-title"]')).toHaveText('فشل المزامنة')
    await expect(page.locator('[data-kind="no_results"]')).toHaveCount(0)

    // «افتح» goes to the object itself.
    await page.getByTestId('bell-filter-budget').click()
    await page.locator('[data-testid^="notification-open-"]').first().click()
    await expect(page).toHaveURL(/\/agency\/budgets\?project=/)
    // The budget opens as a centred dialog over the whole screen, header included; close it as a person would before
    // reaching for the bell again (whether it had drawn yet was a race — webkit lost it).
    // The drawer draws after the budget's own read returns; webkit under the CI shard's load took longer than the default
    // 5s on four PRs in a row (the same assertion, the same expectation — it only waits for the real thing to arrive).
    await expect(page.getByTestId('budget-drawer')).toBeVisible({ timeout: 20_000 })
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('budget-drawer')).toHaveCount(0)

    // One call clears all 113, not just the 100 rows the panel loaded.
    await bell(page).click()
    await page.getByRole('button', { name: 'تعليم الكل كمقروء' }).click()
    await expect(page.getByTestId('bell-badge')).toHaveCount(0, { timeout: 15_000 })
    await page.reload()
    await expect(bell(page)).toBeVisible()
    await expect(page.getByTestId('bell-badge')).toHaveCount(0)
  })

  test('two different objects with the same finding are two notifications, each named by it (the control — the same-object repeat is BellNotificationsTest)', async ({ page }) => {
    expect(raise(['--count=2', '--kind=roas_drop'])).toBe(2)

    await page.goto('/agency/dashboard')
    await bell(page).click()
    await expect(page.locator('[data-kind="roas_drop"]')).toHaveCount(2)
    await expect(page.locator('[data-kind="roas_drop"] [data-testid="notification-title"]').first()).toHaveText('انخفاض العائد')
  })
})

test.describe('the ping', () => {
  test.beforeEach(() => { raise(['--purge', '--count=0']) })

  test('plays once for a batch of five critical, not before the first gesture, not for info, not in quiet hours, not when disabled', async ({ page }) => {
    const problems: string[] = []
    page.on('console', (m) => { if (m.type() === 'error' || /AudioContext/i.test(m.text())) problems.push(m.text()) })
    page.on('pageerror', (e) => problems.push(e.message))
    await watchAudio(page)

    await page.goto('/agency/dashboard')
    await expect(bell(page)).toBeVisible()

    // Before any gesture the browser would refuse audio: nothing plays, and nothing is logged.
    raise(['--count=2', '--kind=budget_100', '--severity=critical'])
    await lookAgain(page)
    await expect(page.getByTestId('bell-badge')).toHaveText('2', { timeout: 15_000 })
    expect(await pings(page)).toBe(0)

    // The first click unlocks audio. A batch of five critical then rings once.
    await page.mouse.click(5, 300)
    raise(['--count=5', '--kind=budget_over', '--severity=critical'])
    await lookAgain(page)
    await expect(page.getByTestId('bell-badge')).toHaveText('7', { timeout: 15_000 })
    await expect.poll(() => pings(page), { timeout: 10_000 }).toBe(1)
    await page.waitForTimeout(1500)
    expect(await pings(page)).toBe(1)

    // Plain information never rings.
    raise(['--count=3', '--kind=report_ready', '--type=report_ready', '--severity=info'])
    await lookAgain(page)
    await expect(page.getByTestId('bell-badge')).toHaveText('10', { timeout: 15_000 })
    expect(await pings(page)).toBe(1)

    // Quiet hours (a window that covers now) silence it.
    const quiet = await page.request.put('/api/v1/settings/notifications', { data: { quiet_hours: { enabled: true, start: '00:00', end: '23:58' } }, headers: await xsrf(page) })
    expect(quiet.ok()).toBeTruthy()
    raise(['--count=1', '--kind=sync_failed', '--type=sync_failed', '--severity=critical'])
    await lookAgain(page)
    await expect(page.getByTestId('bell-badge')).toHaveText('11', { timeout: 15_000 })
    expect(await pings(page)).toBe(1)

    // «Quiet hours off» and the sound switched off: silent as well.
    await page.request.put('/api/v1/settings/notifications', { data: { quiet_hours: { enabled: false, start: '22:00', end: '08:00' } }, headers: await xsrf(page) })
    await page.request.put('/api/v1/notifications/alert-settings', { data: { sound_enabled: false }, headers: await xsrf(page) })
    raise(['--count=1', '--kind=budget_100', '--severity=critical'])
    await lookAgain(page)
    await expect(page.getByTestId('bell-badge')).toHaveText('12', { timeout: 15_000 })
    expect(await pings(page)).toBe(1)

    // And with it back on, a new critical rings again.
    await page.request.put('/api/v1/notifications/alert-settings', { data: { sound_enabled: true }, headers: await xsrf(page) })
    raise(['--count=1', '--kind=budget_100', '--severity=critical'])
    await lookAgain(page)
    await expect.poll(() => pings(page), { timeout: 15_000 }).toBe(2)

    expect(problems, 'no console error, no autoplay warning').toEqual([])
  })
})

const xsrf = (page: Page) => csrfHeaders(page.request)
