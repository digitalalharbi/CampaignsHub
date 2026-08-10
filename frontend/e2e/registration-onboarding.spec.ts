import { expect, test } from '@playwright/test'
import { aFreshSaudiNumber, signIn, switchToEnglish } from './helpers'

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

/**
 * Walk the whole gated registration for a given KIND OF ACCOUNT.
 *
 * The path is what decides the account type now — LAUNCH-PRICING-001. «كيف تريد البدء؟» is asked of
 * everyone, its answer is submitted with the application, and onboarding therefore no longer asks
 * for the account type or the service a second time: it opens at the workspace step with both
 * already answered. So the caller names the path it wants, and the plan that path is actually sold.
 */
async function registerAndVerify(
  page: import('@playwright/test').Page,
  email: string,
  workspace: string,
  opts: { journey: 'self-service' | 'multi-client'; plan: string; selfType?: 'brand' | 'in_house_team' },
) {
  await page.goto('/register')
  await switchToEnglish(page)
  await page.getByLabel(/Organization name|اسم المؤسسة/).fill(workspace)
  await page.getByLabel(/Full name|الاسم الكامل/).fill('New Owner')
  await page.getByLabel(/Email|البريد/).fill(email)
  // Required since PHONE-VERIFY-001 — no account activates without a verified number.
  await page.getByTestId('phone').fill(aFreshSaudiNumber())
  await page.locator('input[type="password"]').first().fill('secret1234')
  await page.locator('input[type="password"]').last().fill('secret1234')
  /*
   * Two steps (PLAN-001e): the details, then the plan. The application is submitted from the second,
   * so the journey walks both — and a plan is CHOSEN, because since PLAN-PAID-001 there is no free
   * tier left to fall through to and an application naming none is refused.
   */
  await page.getByRole('button', { name: /Continue|التالي/ }).click()
  await expect(page.getByTestId('register-panel-plan')).toBeVisible()
  /*
   * The path is answered before a plan exists to choose — LAUNCH-PRICING-001.
   *
   * Plans differ BY path now: «for my clients» is sold Agency alone. So the catalogue is not
   * rendered until the question above it has an answer, and this walk answers it.
   */
  await page.getByTestId(`journey-${opts.journey}`).click()
  // The self-managed path asks which kind of self-managed account; the agency path does not.
  if (opts.selfType !== undefined) {
    await page.getByLabel(/Account type|نوع الحساب/).selectOption(opts.selfType)
  }
  await page.getByTestId(`plan-${opts.plan}`).click()
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
  /*
   * …and the phone before the money (PHONE-VERIFY-001). A proven address says nothing about the
   * number, so the mobile gate is what the application is waiting on next.
   */
  await expect(page.getByTestId('registration-status')).toHaveAttribute('data-state', 'mobile_verification_required')
  await verifyMobile(page)

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
 * Answer the mobile challenge.
 *
 * No SMS provider is wired, so the dev code — exposed outside production only, the same affordance
 * that keeps the email link walkable — stands in for the message. Asking for a new one is what puts
 * it on screen, which is also the real recovery a customer whose code never arrived would use.
 */
async function verifyMobile(page: import('@playwright/test').Page) {
  await page.getByTestId('registration-resend-code').click()

  const shown = page.getByText(/Dev code|رمز التطوير/)
  await expect(shown).toBeVisible({ timeout: 20000 })

  const code = (await shown.innerText()).match(/(\d{6})/)?.[1]
  expect(code, 'no dev code was issued for the mobile challenge').toBeTruthy()

  await page.getByLabel(/code we sent to your mobile|رمز التحقق المرسل إلى جوالك/i).fill(code!)
  await page.getByRole('button', { name: /Confirm code|تأكيد الرمز/ }).click()
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
  /*
   * «I run campaigns for several clients» — which IS the agency account type, and is sold Agency.
   *
   * The wizard's account-type and service steps are not walked any more: both were answered at
   * signup, so onboarding opens at the workspace step with them already filled. Clicking «Agency»
   * here would be asking the same question twice, which is the incoherence LAUNCH-PRICING-001
   * removed.
   */
  await registerAndVerify(page, `agency.${tag}@example.com`.toLowerCase(), `Agency ${tag}`, {
    journey: 'multi-client', plan: 'agency',
  })

  await page.getByLabel(/Workspace name|اسم مساحة العمل/).fill('My Agency')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByLabel(/Client name|اسم العميل/).fill('First Client')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByLabel(/Project name|اسم المشروع/).fill('First Project')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByRole('button', { name: /Go to dashboard|لوحة التحكم/ }).click()

  /*
   * The AGENCY portal, not merely «a dashboard» — that binding is the whole point of the signup
   * question (LAUNCH-PRICING-001): «لعملائي» → `/agency`, «لحملاتي وأعمالي» → `/app`. A walk that
   * asserted `/dashboard` alone passed for either portal and so proved neither.
   */
  await expect(page).toHaveURL(/\/agency\/dashboard/)
  // Personal full menu: Clients is present.
  await switchToEnglish(page)
  await expect(page.getByRole('link', { name: /Clients|العملاء/ }).first()).toBeVisible()

  // Persistence across reload — stays onboarded on the dashboard.
  await page.reload()
  await expect(page).toHaveURL(/\/agency\/dashboard/)
})

test('company (brand) account: register → onboard → simplified menu, no agency tools', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`
  // A brand runs its own campaigns, so it is the self-managed path with «Brand» as the account type.
  await registerAndVerify(page, `brand.${tag}@example.com`.toLowerCase(), `Brand ${tag}`, {
    journey: 'self-service', plan: 'starter', selfType: 'brand',
  })

  await page.getByLabel(/Workspace name|اسم مساحة العمل/).fill('BrandCo')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  // Company skips the client step → straight to first project.
  await page.getByLabel(/Project name|اسم المشروع/).fill('Brand Project')
  await page.getByRole('button', { name: /Continue|متابعة/ }).click()
  await page.getByRole('button', { name: /Go to dashboard|لوحة التحكم/ }).click()

  // The SELF-managed path is sold `/app`, and this is where the walk proves it landed there.
  await expect(page).toHaveURL(/\/app\/dashboard/)
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
  await page.getByTestId('phone').fill(aFreshSaudiNumber())
  await page.locator('input[type="password"]').first().fill('secret1234')
  await page.locator('input[type="password"]').last().fill('secret1234')
  await page.getByRole('button', { name: /Continue|التالي/ }).click()
  await page.getByTestId('journey-self-service').click()

  /*
   * Nothing on sale is free (PLAN-PAID-001) — «البداية» included — and every plan opens with a paid
   * introductory month (PAY-AUDIT-003).
   *
   * The FIGURES come from the catalogue rather than from literals. This asserted `99` and `990`, and
   * both broke the moment the owner re-denominated the plans in USD: a price is a commercial term
   * somebody edits from /admin, and a test that writes one down is testing the seeder.
   */
  const priced = await page.request.get('/api/v1/plans').then((r) => r.json())
  const starterPlan = priced.data.plans.find((p: { code: string }) => p.code === 'starter')

  await expect(page.getByTestId('plan-starter')).toBeEnabled()
  await expect(page.getByTestId('plan-starter')).toContainText(String(Number(starterPlan.price_monthly)))
  await expect(page.getByTestId('plan-growth-intro')).toBeVisible()

  // The annual term quotes the WHOLE annual amount, not a monthly one — stated before payment.
  await page.getByTestId('plan-interval-annual').click()
  await expect(page.getByTestId('plan-starter')).toBeEnabled()
  await expect(page.getByTestId('plan-starter')).toContainText(String(Number(starterPlan.price_annual)))
  // …and the introductory month is NOT advertised beside a year, because the year is bought outright.
  await expect(page.getByTestId('plan-growth-intro')).toHaveCount(0)
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
  await page.getByTestId('phone').fill(aFreshSaudiNumber())
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
  await page.getByTestId('journey-self-service').click()

  // Going back keeps everything, secrets included.
  await page.getByTestId('register-back').click()
  await expect(page.getByLabel(/Email|البريد/)).toHaveValue(`weak.${tag}@example.com`.toLowerCase())
  await expect(page.locator('input[type="password"]').first()).toHaveValue('secret1234')
})

/**
 * **The path decides the plans, and the plans are priced in USD** — LAUNCH-PRICING-001.
 *
 * The incoherence this closes was on screen: both paths were shown the same three plans, with
 * Growth and Agency describing themselves in terms of agencies even to somebody who had just said
 * they run their own campaigns. A price list that does not change when the question changes is not a
 * choice; it is a table somebody has to interpret.
 *
 * Asserted live rather than in a unit test because every part of it is a rendering decision made
 * from server data: which plans the catalogue returns, which the path admits, and what currency the
 * figures carry. The currency especially — SUB-USD-001 is the claim that a NEW customer never sees
 * SAR anywhere in a CampaignsHub subscription price, and only a real page can prove that.
 */
test('the signup path decides which plans exist, and every price is USD', async ({ page }, testInfo) => {
  const tag = `${testInfo.project.name}-${Date.now()}`

  await page.goto('/register')
  await switchToEnglish(page)
  await page.getByLabel(/Organization name|اسم المؤسسة/).fill(`Paths ${tag}`)
  await page.getByLabel(/Full name|الاسم الكامل/).fill('Path Picker')
  await page.getByLabel(/Email|البريد/).fill(`paths.${tag}@example.com`.toLowerCase())
  await page.getByTestId('phone').fill(aFreshSaudiNumber())
  await page.locator('input[type="password"]').first().fill('secret1234')
  await page.locator('input[type="password"]').last().fill('secret1234')
  await page.getByRole('button', { name: /Continue|التالي/ }).click()

  // No path, no price list — three plans for two different products is the thing being removed.
  await expect(page.getByTestId('register-journey-required')).toBeVisible()
  await expect(page.getByTestId('plan-starter')).toHaveCount(0)
  await expect(page.getByTestId('plan-agency')).toHaveCount(0)

  // «I run my own campaigns» — Starter and Growth, and never Agency.
  await page.getByTestId('journey-self-service').click()
  await expect(page.getByTestId('plan-starter')).toBeVisible()
  await expect(page.getByTestId('plan-growth')).toBeVisible()
  await expect(page.getByTestId('plan-agency')).toHaveCount(0)
  // Growth is the recommended one, and it is the only plan carrying the introductory offer.
  await expect(page.getByTestId('plan-growth-recommended')).toBeVisible()
  await expect(page.getByTestId('plan-growth-commitment')).toBeVisible()
  await expect(page.getByTestId('plan-starter-intro')).toHaveCount(0)

  // «I run campaigns for several clients» — Agency alone. Growth does not carry agency work.
  await page.getByTestId('journey-multi-client').click()
  await expect(page.getByTestId('plan-agency')).toBeVisible()
  await expect(page.getByTestId('plan-starter')).toHaveCount(0)
  await expect(page.getByTestId('plan-growth')).toHaveCount(0)

  /*
   * Every figure on this step is USD — SUB-USD-001.
   *
   * Read from the rendered card rather than from the API, because the defect this guards against was
   * a page showing SAR while the catalogue said USD. And asserted as «no SAR» as well as «USD», so a
   * card that showed both would not pass.
   */
  await page.getByTestId('journey-self-service').click()
  for (const code of ['starter', 'growth']) {
    await expect(page.getByTestId(`plan-${code}`)).toContainText('USD')
    await expect(page.getByTestId(`plan-${code}`)).not.toContainText('SAR')
  }

  // The whole comparison is one press away, and it reads from the same catalogue.
  await page.getByTestId('plan-compare-open').click()
  await expect(page.getByTestId('plan-comparison')).toBeVisible()
  /*
   * Ad accounts, NOT clients.
   *
   * This asserted the `clients` row, and `comparisonFor()` correctly drops it on the self-managed
   * path — «العملاء» in front of somebody who runs their own campaigns invites them to buy for a
   * need they do not have. The assertion was pinning the behaviour that was removed on purpose, so
   * it moves to a row this reader is actually shown.
   */
  await expect(page.getByTestId('compare-connections-starter')).toBeVisible()
  await expect(page.getByTestId('compare-connections-growth')).toBeVisible()
})
