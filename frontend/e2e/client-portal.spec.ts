import { expect, test } from '@playwright/test'
import { signInWithCode, submitVerifiedRequest, switchToEnglish } from './helpers'

/**
 * External Client Portal acceptance (mandated flow): submit a request → verify phone + email → receive a
 * request number → sign into the client portal (OTP) → see the request → open it → reply → the reply persists
 * across a reload. Runs on Chromium/Firefox/WebKit. No provider is wired, so delivery is honestly recorded as
 * "awaiting provider" — the portal surfaces that state rather than faking a send.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test('guest submits a verified request then tracks it in the client portal', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`
  const email = `portal.${tag}@example.com`.toLowerCase()
  const phone = `+96650${String(Date.now()).slice(-7)}`
  const company = `Portal Co ${tag}`

  // 1) Submit through the mandatory OTP verification, get a reference.
  const reference = await submitVerifiedRequest(page, {
    name: 'Portal Client', email, phone, company, objective: 'Track this in the client portal.',
  })
  expect(reference).toMatch(/REQ-\d{4}-[A-Z0-9]{6}/)

  /*
   * 2) Sign in at the one door there is (LOGIN-UNIFIED-001).
   *
   * The contact is not asked which portal they want and is never shown a password field: they type
   * the address they filed with, the server recognises it as a contact rather than an operator, and
   * the code step renders. Outside production the issued code is returned and filled for us.
   */
  await page.goto('/login')
  await switchToEnglish(page)
  await signInWithCode(page, email)

  // 3) The dashboard lists the request. A contact named on exactly one of the agency's clients is
  //    sent straight into that space rather than shown a picker with one option (PORTAL-CLIENT-001),
  //    so the landing URL is the space itself. A contact with no client space yet stays at /portal.
  await expect(page).toHaveURL(/\/portal(\/clients\/[^/]+)?$/)
  await expect(page.getByText(reference)).toBeVisible()

  // 4) Open it → reply → the message persists after a reload.
  await page.getByText(reference).click()
  await expect(page).toHaveURL(/\/portal(\/clients\/[^/]+)?\/requests\/REQ-/)
  await expect(page.getByText(/Timeline|المسار/)).toBeVisible()
  const msg = `Any update on ${tag}?`
  await page.getByLabel(/Message|رسالة/).fill(msg)
  await page.getByRole('button', { name: /^Send$|^إرسال$/ }).click()
  // The reply posts then the detail refetches — allow for a network round-trip under load.
  await expect(page.getByText(msg)).toBeVisible({ timeout: 15000 })

  await page.reload()
  await switchToEnglish(page)
  await expect(page.getByText(msg)).toBeVisible({ timeout: 15000 }) // persisted
})
