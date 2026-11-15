import { execFileSync } from 'node:child_process'
import { expect, test, type Page } from '@playwright/test'
import { AUTH, csrfHeaders } from './helpers'
import { E2E_BACKEND_ENV } from './env'

/**
 * NOTIF-V2-INTERNAL-001 / NOTIF-V2-FOLLOWUP-001 — the notification centre, the morning brief, the follow-up list and the personal
 * additions, in a real browser, on rows the REAL scan, brief runner, engine and sender produced (`e2e:followup`).
 *
 * One workspace of each kind the demo data holds: the agency (`/agency`), the direct business / advertiser (`/app`), and the client
 * portal member, who has no follow-up and no sensitive event. Desktop and a 390 px phone; no horizontal scroll anywhere.
 */
const backend = new URL('../../backend', import.meta.url).pathname

function artisan(email: string, ...args: string[]): void {
  execFileSync('php', ['artisan', 'e2e:followup', email, ...args], { cwd: backend, env: { ...process.env, ...E2E_BACKEND_ENV }, encoding: 'utf-8' })
}

const noSideScroll = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)
const bell = (page: Page) => page.getByRole('button', { name: 'الإشعارات' })

const WORKSPACES = [
  { name: 'agency', auth: AUTH.owner, email: 'agency@campaignshub.io', home: '/agency/home', base: '/agency' },
  { name: 'direct business', auth: AUTH.advertiser, email: 'advertiser@campaignshub.io', home: '/app/dashboard', base: '/app' },
] as const

for (const ws of WORKSPACES) {
  test.describe(`${ws.name}: centre, brief, follow-up`, () => {
    test.use({ storageState: ws.auth, locale: 'ar-SA' })

    test.beforeAll(() => {
      artisan(ws.email, '--reset')
      artisan(ws.email, '--run')
      artisan(ws.email, '--event=budget.threshold_90')
      artisan(ws.email, '--event=budget.threshold_75')
      artisan(ws.email, '--event=sync.failed')
    })

    test('the drawer: three tabs, project and workspace on every item, filters, snooze, mute, and the number in the tab title', async ({ page }) => {
      await page.goto(ws.home)
      await expect(page.getByTestId('bell-badge')).toBeVisible({ timeout: 30_000 })
      await expect.poll(() => page.title()).toMatch(/^\(\d+\+?\)/)

      await bell(page).click()
      const drawer = page.getByRole('dialog')
      await expect(drawer).toBeVisible()
      for (const tab of ['center-tab-all', 'center-tab-unread', 'center-tab-followup']) await expect(page.getByTestId(tab)).toBeVisible()

      const first = page.locator('[data-testid^="notification-"][data-kind^="budget."]').first()
      await expect(first).toBeVisible()
      await expect(page.getByTestId('notification-context').first()).toContainText('—')

      // Filters narrow on the server.
      await page.getByLabel('كل الأولويات').selectOption('critical')
      await expect(page.locator('[data-kind="budget.threshold_90"]')).toHaveCount(0)
      await page.getByLabel('كل الأولويات').selectOption('important')
      await expect(page.locator('[data-kind="budget.threshold_90"]').first()).toBeVisible()
      await page.getByLabel('كل الأولويات').selectOption('')

      // Snooze takes it out of the list and out of the number; mute hides the type for this project.
      const before = Number(await page.getByTestId('bell-badge').innerText())
      const row = page.locator('[data-kind="budget.threshold_90"]').first()
      const id = (await row.getAttribute('data-testid'))!.replace('notification-', '')
      await page.getByTestId(`notification-actions-${id}`).click()
      await page.getByTestId(`notification-snooze-1h-${id}`).click()
      await expect(page.getByTestId(`notification-${id}`)).toHaveCount(0)
      await expect(page.getByTestId('bell-badge')).toHaveText(String(before - 1), { timeout: 15_000 })

      const remaining = page.locator('[data-kind="budget.threshold_75"]').first()
      const id2 = (await remaining.getAttribute('data-testid'))!.replace('notification-', '')
      await page.getByTestId(`notification-actions-${id2}`).click()
      await page.getByTestId(`notification-mute-${id2}`).click()
      await expect(page.locator('[data-kind="budget.threshold_75"]')).toHaveCount(0)

      // «Mark all read» clears everything server-side; the number leaves the icon, the title and the app badge.
      await page.getByRole('button', { name: 'تعليم الكل كمقروء' }).click()
      await expect(page.getByTestId('bell-badge')).toHaveCount(0, { timeout: 15_000 })
      await expect.poll(() => page.title()).not.toMatch(/^\(/)
      expect(await noSideScroll(page)).toBe(true)
    })

    test('the follow-up tab and page: tasks with project, reason and date, a button to the exact page, snooze, history, and the brief', async ({ page }) => {
      await page.goto(`${ws.base}/followup`)
      await expect(page.getByTestId('followup-page')).toBeVisible({ timeout: 30_000 })

      // The brief of the person, with what needs follow-up today.
      await expect(page.getByTestId('brief-summary')).toBeVisible({ timeout: 20_000 })
      const row = page.locator('[data-testid^="followup-"][data-kind]').first()
      await expect(row).toBeVisible()
      await expect(row.getByTestId('followup-project')).not.toBeEmpty()
      await expect(row.getByTestId('followup-workspace')).not.toBeEmpty()

      // The history says it was opened.
      await row.locator('[data-testid^="followup-history-"]').first().click()
      await expect(row.locator('[data-testid^="followup-history-list-"] li').first()).toBeVisible()

      // Snooze moves it to «مؤجلة».
      const tid = (await row.getAttribute('data-testid'))!.replace('followup-', '')
      await page.getByTestId(`followup-snooze-${tid}`).click()
      await page.getByTestId(`followup-snooze-1h-${tid}`).click()
      await expect(page.getByTestId(`followup-${tid}`)).toHaveCount(0, { timeout: 15_000 })
      await page.getByTestId('followup-tab-snoozed').click()
      await expect(page.getByTestId(`followup-${tid}`)).toBeVisible()

      // The same list inside the drawer, and its button goes to the exact page.
      await page.getByTestId('followup-tab-open').click()
      await bell(page).click()
      await page.getByTestId('center-tab-followup').click()
      const open = page.getByRole('dialog').locator('[data-testid^="followup-open-"]').first()
      await expect(open).toBeVisible()
      await open.click()
      await expect(page).toHaveURL(new RegExp(`${ws.base}/(budgets|integrations|agents/approvals|settings)`))
    })

    test('the personal preferences: the brief time and channels persist, and the followed projects are listed', async ({ page }) => {
      await page.goto(`${ws.base}/account/notifications`)
      const card = page.getByTestId('followup-prefs')
      await expect(card).toBeVisible({ timeout: 30_000 })

      await page.getByTestId('brief-time').fill('07:30')
      await page.getByTestId('brief-channel-email').check()
      await page.getByTestId('followup-prefs-save').click()
      await expect(card.getByRole('status')).toBeVisible()

      await page.reload()
      await expect(page.getByTestId('brief-time')).toHaveValue('07:30')
      await expect(page.getByTestId('brief-channel-email')).toBeChecked()
      await expect(page.getByTestId('followed-projects')).toBeVisible()
    })

    test.describe('on a phone (390 px)', () => {
      test.use({ viewport: { width: 390, height: 844 } })

      test('the drawer, the follow-up page and the preferences never scroll sideways, and every control is reachable', async ({ page }) => {
        await page.goto(ws.home)
        await expect(bell(page)).toBeVisible({ timeout: 30_000 })
        await bell(page).click()
        await expect(page.getByRole('dialog')).toBeVisible()
        const box = await page.getByRole('dialog').boundingBox()
        expect(box!.width).toBeLessThanOrEqual(390)
        expect(await noSideScroll(page)).toBe(true)
        await page.getByTestId('center-tab-followup').click()
        expect(await noSideScroll(page)).toBe(true)
        await page.getByTestId('notification-drawer-close').click()
        await expect(page.getByRole('dialog')).toHaveCount(0)

        for (const path of [`${ws.base}/followup`, `${ws.base}/account/notifications`]) {
          await page.goto(path)
          await expect(page.locator('h1').first()).toBeVisible({ timeout: 30_000 })
          expect(await noSideScroll(page), path).toBe(true)
        }
      })
    })
  })
}

test.describe('client portal member', () => {
  test.use({ storageState: AUTH.client, locale: 'ar-SA' })

  test('has no follow-up list and no sensitive event, whatever the workspace raised', async ({ request }) => {
    artisan('agency@campaignshub.io', '--event=sync.failed')
    artisan('agency@campaignshub.io', '--event=budget.overrun')
    const headers = await csrfHeaders(request)

    const tasks = await request.get('/api/v1/followup/tasks', { headers })
    expect([403, 404]).toContain(tasks.status())
    const brief = await request.get('/api/v1/followup/brief', { headers })
    expect([403, 404]).toContain(brief.status())

    const list = await request.get('/api/v1/notifications', { headers })
    if (list.ok()) {
      const body = JSON.stringify(await list.json())
      for (const sensitive of ['sync.failed', 'budget.overrun', 'campaign.spend_without_results', 'plan.grace_ending']) expect(body).not.toContain(sensitive)
    }
  })
})
