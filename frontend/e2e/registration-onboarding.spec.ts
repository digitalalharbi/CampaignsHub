import { expect, test } from '@playwright/test'
import { signIn, switchToEnglish } from './helpers'

/**
 * Full registration → onboarding acceptance: register → verify email → account type → service → workspace →
 * first client + project → dashboard, with the PERSONAL full menu; a COMPANY (brand) account gets the
 * SIMPLIFIED menu and cross-tenant/agency capabilities are blocked. Persistence verified across reload.
 * Runs on Chromium/Firefox/WebKit. No mail provider → the dev verify link is used (hard-gated to non-prod).
 *
 * Since SIGNUP-002 the first half of that journey is the gated registration path: submitting the form
 * opens an APPLICATION and lands on its status page. Since PLAN-PAID-001 confirming the email is no
 * longer the last gate either — every plan is paid, so what creates the workspace is a payment the
 * gateway confirmed. This machine has no gateway credentials, so the journey goes through the
 * sandbox adapter (PAY-SANDBOX-001): a real signature over a real webhook, no real money.
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
  /*
   * Two steps (PLAN-001e): the details, then the plan. The application is submitted from the second,
   * so the journey walks both — and a plan is CHOSEN, because since PLAN-PAID-001 there is no free
   * tier left to fall through to and an application naming none is refused.
   */
  await page.getByRole('button', { name: /Continue|التالي/ }).click()
  await expect(page.getByTestId('register-panel-plan')).toBeVisible()
  await page.getByTestId('plan-starter').click()
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

  /*
   * …and a verified email is no longer enough. The application sits at the payment gate with no
   * workspace behind it, which is the guarantee PLAN-PAID-001 exists to make.
   */
  await expect(page.getByTestId('registration-status')).toHaveAttribute('data-state', 'approved_awaiting_payment')
  await payThroughSandbox(page)

  /*
   * The workspace was created by a webhook, which has no browser and therefore left no session
   * behind. So the new owner signs in — with the password they chose when they applied, through the
   * one door there is (LOGIN-UNIFIED-001) — and lands where the wizard left off.
   */
  await signIn(page, email, 'secret1234')
  await expect(page).toHaveURL(/\/onboarding/, { timeout: 20000 })
}

/**
 * Pay, through the sandbox gateway (PAY-SANDBOX-001).
 *
 * Deliberately the real journey rather than a shortcut: the Pay button opens a checkout, the
 * gateway's own page takes the confirmation, and the account activates because a SIGNED event
 * reached the webhook. A test that wrote a paid row instead would prove the product can be activated
 * by something other than money — the one thing this path exists to prevent.
 */
async function payThroughSandbox(page: import('@playwright/test').Page) {
  // The sandbox is named as the sandbox on the page where somebody is about to pay.
  await expect(page.getByTestId('registration-payment-sandbox')).toBeVisible()

  await page.getByTestId('registration-pay').click()
  await page.getByTestId('sandbox-pay').click({ timeout: 20000 })

  // The gateway sends the customer back to their own status page, which reads the state from the
  // server — so an event that had been refused would land them on an unpaid application, not a
  // page telling them it worked.
  await expect(page.getByTestId('registration-status')).toHaveAttribute('data-state', 'active', { timeout: 20000 })
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

/**
 * The plan step is driven by the real catalogue (PLAN-001e).
 *
 * Not a screenshot of a price list: switching the term must change the figure each plan quotes, and
 * the annual amount must be on screen BEFORE anybody is asked to pay it — which is what the brief
 * means by «يُعرض بوضوح قبل الدفع».
 */
test('the plan step reads the catalogue and quotes both terms before payment', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`

  await page.goto('/register')
  await switchToEnglish(page)
  await page.getByLabel(/Organization name|اسم المؤسسة/).fill(`Plans ${tag}`)
  await page.getByLabel(/Full name|الاسم الكامل/).fill('Plan Picker')
  await page.getByLabel(/Email|البريد/).fill(`plans.${tag}@example.com`.toLowerCase())
  await page.locator('input[type="password"]').first().fill('secret1234')
  await page.locator('input[type="password"]').last().fill('secret1234')
  await page.getByRole('button', { name: /Continue|التالي/ }).click()

  // Nothing on sale is free any more (PLAN-PAID-001) — «البداية» included.
  await expect(page.getByTestId('plan-starter')).toBeEnabled()
  await expect(page.getByTestId('plan-starter')).toContainText('99')
  await expect(page.getByTestId('plan-growth-trial')).toBeVisible()

  // The annual term quotes the WHOLE annual amount, not a monthly one — stated before payment.
  await page.getByTestId('plan-interval-annual').click()
  await expect(page.getByTestId('plan-starter')).toBeEnabled()
  await expect(page.getByTestId('plan-starter')).toContainText('990')
  await page.getByTestId('plan-interval-monthly').click()

  // Applying without choosing one is refused — there is no free plan to fall through to.
  await page.getByRole('button', { name: /Create account|إنشاء حساب/ }).click()
  await expect(page.getByTestId('register-plan-error')).toBeVisible()
  await expect(page).toHaveURL(/\/register/)

  // A chosen plan travels with the application.
  await page.getByTestId('plan-growth').click()
  await expect(page.getByTestId('plan-growth')).toHaveAttribute('data-selected', 'true')
  await page.getByRole('button', { name: /Create account|إنشاء حساب/ }).click()
  await expect(page).toHaveURL(/\/signup\/status/)
})

/**
 * SIGNUP-STEP-001 — the account step is a gate, and its errors never appear on the packages step.
 *
 * The failure this replaces was real and specific: a weak password was accepted by the form, carried
 * to the packages step, and surfaced there beside a price list with no field on screen to correct.
 */
test('an invalid password is caught on the account step, not beside the price list', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`

  await page.goto('/register')
  await switchToEnglish(page)
  await page.getByLabel(/Organization name|اسم المؤسسة/).fill(`Weak ${tag}`)
  await page.getByLabel(/Full name|الاسم الكامل/).fill('Weak Password')
  await page.getByLabel(/Email|البريد/).fill(`weak.${tag}@example.com`.toLowerCase())
  await page.locator('input[type="password"]').first().fill('short')
  await page.locator('input[type="password"]').last().fill('short')
  await page.getByRole('button', { name: /Continue|التالي/ }).click()

  // Refused here, on the step that has the field.
  await expect(page.getByTestId('register-panel-plan')).toHaveCount(0)
  await expect(page.getByTestId('error-summary')).toContainText(/at least 8 characters/i)

  // Corrected, the complaint goes — and does not follow onto the packages step.
  await page.locator('input[type="password"]').first().fill('secret1234')
  await page.locator('input[type="password"]').last().fill('secret1234')
  await page.getByRole('button', { name: /Continue|التالي/ }).click()

  await expect(page.getByTestId('register-panel-plan')).toBeVisible()
  await expect(page.getByTestId('error-summary')).toHaveCount(0)

  // Going back keeps everything, secrets included.
  await page.getByTestId('register-back').click()
  await expect(page.getByLabel(/Email|البريد/)).toHaveValue(`weak.${tag}@example.com`.toLowerCase())
  await expect(page.locator('input[type="password"]').first()).toHaveValue('secret1234')
})
