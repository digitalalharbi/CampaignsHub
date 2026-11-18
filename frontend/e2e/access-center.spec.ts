import { expect, test } from '@playwright/test'
import { AUTH, E2E_ORIGIN, openRail } from './helpers'

/**
 * ACCESS-CENTER-001 — «الفريق والنطاقات» in a real browser: the owner invites a member with a category and one area
 * switched off for THIS invitation, sees «ماذا سيرى» before sending, and the invitee — a guest with no account —
 * accepts and holds exactly that: the campaigns, and no finance. Also the AD-GROUP-TERM-001 rail label.
 */
test.use({ storageState: AUTH.owner })

test('owner invites with a category and «المالية» switched off → invitee accepts → no finance', async ({ page, browser }, testInfo) => {
  const email = `access.${Date.now()}@example.com`

  await page.goto('/agency/settings/permissions')
  await expect(page.getByTestId('access-center')).toBeVisible({ timeout: 30_000 })
  await page.getByTestId('access-invite').click()

  const dialog = page.getByTestId('invite-dialog')
  await dialog.getByTestId('invite-email').fill(email)
  await dialog.getByTestId('invite-category-media-buyer').click()
  await dialog.getByTestId('invite-customise').click()
  await dialog.getByTestId('toggle-finance-none').click()
  await expect(dialog.getByTestId('invite-preview').getByTestId('area-chip-finance')).toHaveAttribute('data-level', 'none')
  await expect(dialog.getByTestId('invite-preview').getByTestId('area-chip-campaigns')).toHaveAttribute('data-level', 'manage')

  const sent = page.waitForResponse((r) => r.url().includes('/api/v1/settings/access/invitations') && r.request().method() === 'POST')
  await dialog.getByTestId('invite-send').click()
  const res = await sent
  expect(res.status()).toBe(201)
  const devLink = (await res.json()).data.dev_link as string
  expect(devLink).toContain('/invite/accept?token=')

  // The invitation is on the board at once, marked as customised.
  await page.keyboard.press('Escape')
  await expect(page.getByTestId(`access-row-invitation-${email}`)).toBeVisible()

  // The invitee, a guest, accepts.
  const guest = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: E2E_ORIGIN })
  const g = await guest.newPage()
  await g.goto(devLink)
  await g.getByLabel(/Full name|الاسم الكامل/).fill('Access Member')
  await g.getByLabel(/Password|كلمة المرور/).fill('secret1234')
  const join = g.getByRole('button', { name: /Join workspace|الانضمام/ })
  await expect(join).toBeEnabled()
  await join.click()
  await expect(g).toHaveURL(/\/(home|dashboard|agency)/, { timeout: 15_000 })

  const me = await g.evaluate(async () => (await (await fetch('/api/v1/auth/me', { headers: { Accept: 'application/json' } })).json()))
  const permissions: string[] = me.data?.permissions ?? me.data?.user?.permissions ?? []
  expect(permissions).toContain('campaigns.view')
  expect(permissions).not.toContain('budget.view')
  await guest.close()
})

test('the rail names the ad-group level «المجموعات»', async ({ page }, testInfo) => {
  await page.goto('/agency/dashboard')
  await openRail(page)
  const rail = page.locator('aside').first()
  await expect(rail.getByRole('link', { name: /^المجموعات$|^Ad groups$/ }).first()).toBeAttached({ timeout: 30_000 })
  await expect(page.getByText('المجموعات الإعلانية')).toHaveCount(0)
})

test('the access center fits a phone: no sideways scroll, the invite button in reach', async ({ page }, testInfo) => {
  await page.setViewportSize({ width: 375, height: 812 })
  await page.goto('/agency/settings/permissions')
  await expect(page.getByTestId('access-center')).toBeVisible({ timeout: 30_000 })
  await expect(page.getByTestId('access-invite')).toBeInViewport()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(0)
  await page.screenshot({ path: testInfo.outputPath('access-center-phone.png'), fullPage: true })
})
