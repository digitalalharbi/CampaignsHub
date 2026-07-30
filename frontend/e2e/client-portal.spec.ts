import { expect, test } from '@playwright/test'
import { submitVerifiedRequest, switchToEnglish } from './helpers'

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

  // 2) Sign into the client portal via email OTP (dev auto-fills the code).
  await page.goto('/portal/login')
  await switchToEnglish(page)
  await page.getByRole('button', { name: /^Email$|^البريد$/ }).click()
  await page.getByLabel(/Contact|وسيلة التواصل/).fill(email)
  await page.getByRole('button', { name: /Send code|إرسال الرمز/ }).click()
  // Wait for the code step (dev auto-fills the code) so Sign in is enabled before we click.
  const signIn = page.getByRole('button', { name: /^Sign in$|^دخول$/ })
  await expect(signIn).toBeEnabled()
  await signIn.click()

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
