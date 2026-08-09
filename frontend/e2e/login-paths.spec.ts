import { expect, test } from '@playwright/test'
import { aFreshSaudiNumber, csrfHeaders, signInWithPhone, switchToEnglish } from './helpers'

/**
 * LOGIN-PATHS-001 + PHONE-SA-001 — two ways in, and a phone field that speaks this market's language.
 *
 * The claims under test are not cosmetic. Choosing between an address and a number is choosing which
 * CREDENTIAL you hold, and it must not become a way to choose a portal; and `05…`, `9665…` and
 * `+9665…` must be one account rather than three, which is only true if the reading happens on the
 * server — a browser-side tidy-up would be undone by the first hand-written payload.
 */
test.use({ storageState: { cookies: [], origins: [] } })

test.describe('the sign-in box offers two paths and no portal', () => {
  test('both paths are offered, and neither is a portal', async ({ page }) => {
    await page.goto('/login')
    await switchToEnglish(page)

    await expect(page.getByTestId('login-paths')).toBeVisible()
    await expect(page.getByTestId('login-path-email')).toHaveAttribute('aria-selected', 'true')
    await expect(page.getByTestId('login-path-phone')).toHaveAttribute('aria-selected', 'false')

    // The portal chooser stays gone (LOGIN-UNIFIED-001) — a method is not a portal.
    for (const key of ['default', 'agency', 'client', 'influencer', 'admin']) {
      await expect(page.getByTestId(`login-portal-${key}`)).toHaveCount(0)
    }
  })

  test('switching to the phone path replaces the form rather than adding to it', async ({ page }) => {
    await page.goto('/login')

    await page.getByTestId('login-path-phone').click()
    await expect(page.getByTestId('login-phone')).toBeVisible()
    await expect(page.getByTestId('login-identify')).toHaveCount(0)

    await page.getByTestId('login-path-email').click()
    await expect(page.getByTestId('login-identify')).toBeVisible()
    await expect(page.getByTestId('login-phone')).toHaveCount(0)
  })

  /** Saudi Arabia is the default and needs no typing — the brief's first phone requirement. */
  test('the country opens on +966 and offers others', async ({ page }) => {
    await page.goto('/login')
    await page.getByTestId('login-path-phone').click()

    const dial = page.getByTestId('login-phone-number-dial-code')
    await expect(dial).toHaveValue('966')

    // …and another country can be chosen, which changes the code and nothing else.
    await dial.selectOption('971')
    await expect(dial).toHaveValue('971')
    await expect(page.getByTestId('login-phone-number')).toHaveValue('')
  })

  test('a number it cannot read is refused before anything is sent', async ({ page }) => {
    await page.goto('/login')
    await switchToEnglish(page)
    await page.getByTestId('login-path-phone').click()

    await page.getByTestId('login-phone-number').fill('not a phone')
    await page.getByTestId('login-phone').locator('button[type="submit"]').click()

    await expect(page.getByText(/valid mobile number/i)).toBeVisible()
    await expect(page.getByTestId('login-code')).toHaveCount(0)
  })
})

/**
 * The phone path, end to end, against an account that really exists.
 *
 * The account is opened through the real registration journey — including the mobile gate, which is
 * what puts a VERIFIED number on the user in the first place. Signing in with it afterwards is the
 * thing being tested; there is no other way to get an account whose phone is trustworthy.
 */
test.describe('signing in with a mobile number', () => {
  test('the national form signs in, and every other spelling is the same number', async ({ page }, testInfo) => {
    const tag = `${testInfo.project.name}-${Date.now()}`
    const national = aFreshSaudiNumber()
    const email = `phone.${tag}@example.com`.toLowerCase()

    await openAccount(page, { email, phone: national, workspace: `Phone ${tag}` })

    // The journey, once, through the browser: `05xxxxxxxx` — what somebody here actually types.
    await page.context().clearCookies()
    await page.goto('/login')
    await signInWithPhone(page, national)
    await expect(page).not.toHaveURL(/\/login/, { timeout: 20000 })

    /*
     * The other spellings are checked against the endpoint rather than by signing in four times.
     *
     * The claim is about NORMALISATION — that `9665…` and `+9665…` are the same number as `05…` —
     * and re-walking a whole browser journey to test a reading rule made this the slowest test in the
     * suite and the one that failed under a loaded three-browser run for want of an SMS round trip.
     * What the browser has to prove is that the path works; that it works for one spelling and not
     * another is a property of the server, and this is the server being asked.
     */
    const withoutZero = national.slice(1)
    for (const spelling of [`966${withoutZero}`, `+966${withoutZero}`, national.replace(/(\d{3})(\d{3})/, '$1 $2 ')]) {
      const res = await page.request.post('/api/v1/auth/phone/start', {
        headers: await csrfHeaders(page.request),
        data: { phone: spelling },
      })
      expect(res.status(), `${spelling} was not accepted`).toBe(200)

      const verificationId = (await res.json()).data.verification_id as string
      const code = (await res.json()).data.dev_code as string

      const signedIn = await page.request.post('/api/v1/auth/phone/verify', {
        headers: await csrfHeaders(page.request),
        data: { verification_id: verificationId, code },
      })
      expect(signedIn.status(), `${spelling} did not reach the same account`).toBe(200)
      expect((await signedIn.json()).data.user.email).toBe(email)
    }
  })

  /**
   * A number nobody holds gets a code step, and then goes nowhere.
   *
   * Both halves matter. Refusing at the first step would tell anybody with a phone book which numbers
   * have accounts here; signing them in would be worse still.
   */
  test('a number nobody holds is answered the same way, and signs nobody in', async ({ page }) => {
    await page.goto('/login')
    await switchToEnglish(page)
    await page.getByTestId('login-path-phone').click()

    await page.getByTestId('login-phone-number').fill('0500000001')
    await page.getByTestId('login-phone').locator('button[type="submit"]').click()

    // The same step a real customer sees — no hint that this number is unknown.
    await expect(page.getByTestId('login-code')).toBeVisible({ timeout: 20000 })

    const field = page.getByTestId('login-code').locator('input[autocomplete="one-time-code"]')
    await expect(field).not.toHaveValue('', { timeout: 20000 })
    await page.getByTestId('login-code').locator('button[type="submit"]').click()

    await expect(page.getByTestId('login-error')).toBeVisible({ timeout: 20000 })
    await expect(page).toHaveURL(/\/login/)
  })
})

/** Open a real account: apply, prove the address, prove the number, pay. */
async function openAccount(
  page: import('@playwright/test').Page,
  { email, phone, workspace }: { email: string; phone: string; workspace: string },
) {
  await page.goto('/register')
  await switchToEnglish(page)

  await page.getByLabel(/Organization name|اسم المؤسسة/).fill(workspace)
  await page.getByLabel(/Full name|الاسم الكامل/).fill('Phone Owner')
  await page.getByLabel(/Email|البريد/).fill(email)
  await page.getByTestId('phone').fill(phone)
  await page.locator('input[type="password"]').first().fill('secret1234')
  await page.locator('input[type="password"]').last().fill('secret1234')
  await page.getByRole('button', { name: /Continue|التالي/ }).click()

  // The path decides which plans exist, so it is answered first — LAUNCH-PRICING-001.
  await page.getByTestId('journey-self-service').click()
  await page.getByTestId('plan-starter').click()
  await page.getByRole('button', { name: /Create account|إنشاء حساب/ }).click()

  await page.getByTestId('registration-dev-verify').click()

  // The mobile gate — the reason the number on this account can be trusted at all.
  await expect(page.getByTestId('registration-status')).toHaveAttribute('data-state', 'mobile_verification_required')
  await page.getByTestId('registration-resend-code').click()

  const shown = page.getByText(/Dev code|رمز التطوير/)
  await expect(shown).toBeVisible({ timeout: 20000 })
  const code = (await shown.innerText()).match(/(\d{6})/)?.[1]
  expect(code, 'no dev code was issued for the mobile challenge').toBeTruthy()

  await page.getByLabel(/code we sent to your mobile|رمز التحقق المرسل إلى جوالك/i).fill(code!)
  await page.getByRole('button', { name: /Confirm code|تأكيد الرمز/ }).click()

  await expect(page.getByTestId('registration-payment-sandbox')).toBeVisible({ timeout: 20000 })
  await page.getByTestId('registration-pay').click()
  await page.getByTestId('sandbox-pay').click({ timeout: 20000 })

  await expect(page.getByTestId('registration-status')).toHaveAttribute('data-state', 'active', { timeout: 20000 })
}
