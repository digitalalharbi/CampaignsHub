import { expect, test } from '@playwright/test'
import { switchToEnglish } from './helpers'

/**
 * Full registration → onboarding acceptance: register → verify email → account type → service → workspace →
 * first client + project → dashboard, with the PERSONAL full menu; a COMPANY (brand) account gets the
 * SIMPLIFIED menu and cross-tenant/agency capabilities are blocked. Persistence verified across reload.
 * Runs on Chromium/Firefox/WebKit. No mail provider → the dev verify link is used (hard-gated to non-prod).
 *
 * Since SIGNUP-002 the first half of that journey is the gated registration path: submitting the form
 * opens an APPLICATION and lands on its status page, and it is confirming the email — not submitting
 * the form — that creates the workspace under the default (auto-activate) policy.
 */
test.use({ storageState: { cookies: [], origins: [] } })

async function registerAndVerify(page: import('@playwright/test').Page, email: string, workspace: string) {
  await page.goto('/register')
  await switchToEnglish(page)
  await page.getByLabel(/Organization name|اسم المؤسسة/).fill(workspace)
  await page.getByLabel(/Full name|الاسم الكامل/).fill('New Owner')
  await page.getByLabel(/Email|البريد/).fill(email)
  await page.locator('input[type="password"]').first().fill('secret1234')
  await page.locator('input[type="password"]').last().fill('secret1234')
  await page.getByRole('button', { name: /Create account|إنشاء حساب/ }).click()

  /*
   * Applying lands on the registration STATUS page, not in the app (SIGNUP-002).
   *
   * The state is read from the page's own attribute rather than from a translated string, so this
   * assertion is about the application's actual state and not about the wording of a label.
   */
  await expect(page).toHaveURL(/\/signup\/status/)
  await expect(page.getByTestId('registration-status')).toHaveAttribute('data-state', 'email_verification_required')

  // No mail provider is configured, so the dev link is what stands in for the message.
  await page.getByTestId('registration-dev-verify').click()
  await expect(page).toHaveURL(/\/onboarding/)
}

test('personal (agency) account: register → onboard → full menu', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`
  await registerAndVerify(page, `agency.${tag}@example.com`.toLowerCase(), `Agency ${tag}`)

  await page.getByRole('button', { name: /^Agency$|^وكالة$/ }).click()
  await page.getByRole('button', { name: /Paid advertising management|الحملات الإعلانية المدفوعة/ }).click()
  await page.getByLabel(/Workspace name|اسم مساحة العمل/).fill('My Agency')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByLabel(/Client name|اسم العميل/).fill('First Client')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByLabel(/Project name|اسم المشروع/).fill('First Project')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByRole('button', { name: /Go to dashboard|لوحة التحكم/ }).click()

  await expect(page).toHaveURL(/\/dashboard/)
  // Personal full menu: Clients is present.
  await switchToEnglish(page)
  await expect(page.getByRole('link', { name: /Clients|العملاء/ }).first()).toBeVisible()

  // Persistence across reload — stays onboarded on the dashboard.
  await page.reload()
  await expect(page).toHaveURL(/\/dashboard/)
})

test('company (brand) account: register → onboard → simplified menu, no agency tools', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`
  await registerAndVerify(page, `brand.${tag}@example.com`.toLowerCase(), `Brand ${tag}`)

  await page.getByRole('button', { name: /^Brand$|^علامة تجارية$/ }).click()
  await page.getByRole('button', { name: /Paid advertising management|الحملات الإعلانية المدفوعة/ }).click()
  await page.getByLabel(/Workspace name|اسم مساحة العمل/).fill('BrandCo')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  // Company skips the client step → straight to first project.
  await page.getByLabel(/Project name|اسم المشروع/).fill('Brand Project')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByRole('button', { name: /Go to dashboard|لوحة التحكم/ }).click()

  await expect(page).toHaveURL(/\/dashboard/)
  await switchToEnglish(page)
  // Simplified menu: Campaigns present, but NO Clients / Requests (the agency tools).
  await expect(page.getByRole('link', { name: /Campaigns|الحملات/ }).first()).toBeVisible()
  await expect(page.getByRole('link', { name: /Clients|العملاء/ })).toHaveCount(0)
  await expect(page.getByRole('link', { name: /Requests|الطلبات/ })).toHaveCount(0)
  // (The API also 403s /app/clients for a company workspace — proven in RegistrationOnboardingTest.)
})
