import { expect, test } from '@playwright/test'
import { AUTH, csrfHeaders, switchToEnglish } from './helpers'

/**
 * Workspace invitation acceptance: the owner invites a member (secure token), and the invitee — with no
 * account yet — accepts via the public link, joining the EXISTING workspace and landing on the dashboard
 * with the correct (personal, full) menu. Runs on Chromium/Firefox/WebKit.
 */
test.use({ storageState: { cookies: [], origins: [] } }) // the accepting invitee is a guest

test('owner invites a member → invitee accepts → joins the workspace', async ({ page, browser }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`
  const email = `member.${tag}@example.com`.toLowerCase()

  // 1) Owner creates the invitation via the API (dev link is returned in non-prod).
  const ownerCtx = await browser.newContext({ storageState: AUTH.owner, baseURL: 'http://localhost:5173' })
  const headers = await csrfHeaders(ownerCtx.request)
  const res = await ownerCtx.request.post('/api/v1/app/team/invitations', {
    headers, data: { email, role_slug: 'analyst' },
  })
  expect(res.status()).toBe(201)
  const devLink = (await res.json()).data.dev_link as string
  expect(devLink).toContain('/invite/accept?token=')
  await ownerCtx.close()

  // 2) The invitee (guest) opens the accept link and joins.
  await page.goto(devLink)
  await switchToEnglish(page)
  await expect(page.getByText(email)).toBeVisible()
  await page.getByLabel(/Full name|الاسم الكامل/).fill('Invited Member')
  await page.getByLabel(/Password|كلمة المرور/).fill('secret1234')
  await page.getByRole('button', { name: /Join workspace|الانضمام/ }).click()

  // 3) Joined the existing workspace → dashboard with the personal full menu. The accept round-trip
  //    (create user + auto-login + first dashboard load) can be slow when the single-threaded dev backend
  //    is under load from the full 3-browser suite — allow generous time for the redirect to land.
  await expect(page).toHaveURL(/\/dashboard/, { timeout: 20_000 })
  await switchToEnglish(page)
  await expect(page.getByRole('link', { name: /Campaigns|الحملات/ }).first()).toBeVisible()

  // Persistence across reload.
  await page.reload()
  await expect(page).toHaveURL(/\/dashboard/)
})
