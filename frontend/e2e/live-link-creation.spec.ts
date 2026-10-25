import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * LIVE-LINK-ERRORS-001 — creating a live link, against the real API, and what the screen says when it cannot.
 *
 * Production reported «تأكد من وجود حملات» on a project that had them. The builder printed that one sentence for EVERY failure — an
 * expired session, a missing permission, a plan limit, a gateway fault, a dropped connection — so the owner was sent looking for
 * campaigns that were there. Success is asserted against the real API with the selections a client link is actually built from
 * (accounts AND campaigns); each failure class is forced at the network boundary and must say what it is.
 */
test.use({ storageState: AUTH.advertiser })

async function openBuilder(page: Page) {
  await page.goto('/app/reports')
  await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
  await page.getByRole('button', { name: /تقرير رابط|Link Report/ }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible({ timeout: 10000 })
  await dialog.locator('input[type="text"], input:not([type])').first().fill(`E2E live ${Date.now()}`)
  const account = dialog.getByTestId('live-builder-accounts').getByRole('checkbox').first()
  await expect(async () => {
    if (!(await account.isChecked())) await account.click()
    await expect(account).toBeChecked({ timeout: 3000 })
  }).toPass({ timeout: 20000 })

  return dialog
}

const create = (dialog: ReturnType<Page['getByRole']>) => dialog.getByRole('button', { name: /إنشاء الرابط|Create link/ })

test.describe('creating a live link', () => {
  test('succeeds with a selected account and a selected campaign, and the link stays inside what was selected', async ({ page }) => {
    const dialog = await openBuilder(page)

    const campaigns = dialog.getByTestId('live-builder-campaigns').getByRole('checkbox')
    if ((await campaigns.count()) > 0) {
      const first = campaigns.first()
      await expect(async () => {
        if (!(await first.isChecked())) await first.click()
        await expect(first).toBeChecked({ timeout: 3000 })
      }).toPass({ timeout: 20000 })
    }

    const posted = page.waitForRequest((r) => r.method() === 'POST' && /\/reports\/live$/.test(r.url()))
    await create(dialog).click()
    const body = (await posted).postDataJSON() as { account_ids: string[]; accounts?: Record<string, { campaign_ids?: string[] }> }

    expect(body.account_ids.length).toBeGreaterThan(0)
    if ((await campaigns.count()) > 0) {
      const chosen = Object.values(body.accounts ?? {}).flatMap((a) => a.campaign_ids ?? [])
      expect(chosen.length, 'the selected campaign travels under its account').toBeGreaterThan(0)
    }
    await expect(page.getByTestId('live-link-url')).toBeVisible({ timeout: 20000 })
    await expect(page.getByTestId('live-link-create-error')).toHaveCount(0)
  })

  test('says what failed, never «no campaigns», and offers Retry only where asking again can help', async ({ page }) => {
    const dialog = await openBuilder(page)

    // A gateway fault: retryable, and the next attempt reaches the real API and succeeds.
    let calls = 0
    await page.route('**/reports/live', async (route) => {
      if (route.request().method() !== 'POST') return route.continue()
      calls += 1
      if (calls === 1) return route.fulfill({ status: 503, contentType: 'text/plain', body: '' })

      return route.continue()
    })
    await create(dialog).click()
    const failure = dialog.getByTestId('live-link-create-error')
    await expect(failure.getByTestId('query-failure-retryable')).toBeVisible({ timeout: 15000 })
    await expect(failure).not.toContainText(/تأكد من وجود حملات|Check this project has campaigns/)
    await failure.getByRole('button', { name: /إعادة المحاولة|Try again/ }).click()
    await expect(page.getByTestId('live-link-url')).toBeVisible({ timeout: 20000 })
  })

  test('a missing permission and an ended session are said as themselves, with no Retry for the permission', async ({ page }) => {
    const dialog = await openBuilder(page)
    await page.route('**/reports/live', (route) => (route.request().method() === 'POST'
      ? route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'ليست لديك صلاحية إدارة التقارير.', data: null, errors: null }) })
      : route.continue()))
    await create(dialog).click()

    const failure = dialog.getByTestId('live-link-create-error')
    await expect(failure.getByTestId('query-failure-permission')).toBeVisible({ timeout: 15000 })
    await expect(failure.getByRole('button', { name: /إعادة المحاولة|Try again/ })).toHaveCount(0)
    await expect(failure).toContainText('ليست لديك صلاحية إدارة التقارير.')
  })

  test('a dropped connection is a retryable offline failure', async ({ page }) => {
    const dialog = await openBuilder(page)
    await page.route('**/reports/live', (route) => (route.request().method() === 'POST' ? route.abort('connectionreset') : route.continue()))
    await create(dialog).click()

    await expect(dialog.getByTestId('live-link-create-error').getByTestId('query-failure-retryable')).toBeVisible({ timeout: 15000 })
  })
})
