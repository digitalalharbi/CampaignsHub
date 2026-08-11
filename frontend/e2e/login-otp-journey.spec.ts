import { expect, test, type Page } from '@playwright/test'
import { DEMO_CLIENT_CONTACT, switchToEnglish } from './helpers'

/**
 * LOGIN-E2E-001 — «الدخول بدون كلمة مرور» is authentication, not a screen that changes.
 *
 * ## What these prove that a URL assertion cannot
 *
 * A sign-in flow can look finished while being hollow: a code that verifies, an address bar that
 * changes, and no session behind it. Every journey here ends by asking the SERVER a question the
 * interface cannot answer on its own — `GET /auth/me` returns the person who signed in, and an
 * endpoint inside the portal they landed in actually answers.
 *
 * ## Why the code is readable at all
 *
 * Outside production the backend returns the code it just issued (`dev_code`, hard-gated
 * server-side) and the page fills the six boxes with it. That affordance is what makes this branch
 * walkable without an inbox, and it is the same one registration and the client portal already use.
 */
test.use({ storageState: { cookies: [], origins: [] } })

/** Ask for a code, and wait until one is actually in the field. */
async function requestCode(page: Page, email: string): Promise<void> {
  await page.getByTestId('login-email').fill(email)
  await page.getByTestId('login-request-code').click()

  await expect(page.getByTestId('login-code'), `${email} was not offered a code step`).toBeVisible({ timeout: 20_000 })
  await expect(
    page.getByTestId('login-otp-5'),
    'the issued code never arrived, so there is nothing to submit',
  ).not.toHaveValue('', { timeout: 20_000 })
}

/** The session, as the SERVER sees it. `null` when there is none. */
async function whoAmI(page: Page): Promise<string | null> {
  const res = await page.request.get('/api/v1/auth/me', {
    headers: { Accept: 'application/json', Origin: new URL(page.url()).origin },
  })

  if (!res.ok()) return null

  return (await res.json()).data?.user?.email ?? null
}

test.describe('the email code opens a real session', () => {
  /**
   * The whole journey for an advertiser: card → code → session → the portal the server chose.
   *
   * The destination is asserted from the URL AND from a protected endpoint, because the first alone
   * would pass for a page that simply navigated.
   */
  test('an advertiser signs in with a code and lands in /app with a live session', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, 'advertiser@campaignshub.io')

    await page.getByTestId('login-code').locator('button[type="submit"]').click()

    await expect(page).toHaveURL(/\/app\//, { timeout: 20_000 })
    expect(await whoAmI(page), 'the URL changed but no session was opened').toBe('advertiser@campaignshub.io')

    // …and a real page inside that portal renders for this session.
    await page.goto('/app/dashboard')
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('an agency operator signs in with a code and lands in /agency', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, 'agency@campaignshub.io')

    await page.getByTestId('login-code').locator('button[type="submit"]').click()

    await expect(page).toHaveURL(/\/(agency|switch)/, { timeout: 20_000 })
    expect(await whoAmI(page)).toBe('agency@campaignshub.io')
  })

  test('the platform owner signs in with a code and lands in /admin', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, 'admin@campaignshub.io')

    await page.getByTestId('login-code').locator('button[type="submit"]').click()

    await expect(page).toHaveURL(/\/admin/, { timeout: 20_000 })
    expect(await whoAmI(page)).toBe('admin@campaignshub.io')
  })

  /**
   * A client contact types the same field and reaches the portal instead.
   *
   * The engine differs — the portal's own, ending in a portal session — and the person is never
   * asked which one they are. Asserted here beside the platform journeys because the whole claim of
   * one door is that these two look identical from the outside.
   */
  test('a client contact reaches /portal through the same button', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, DEMO_CLIENT_CONTACT)

    await page.getByTestId('login-code').locator('button[type="submit"]').click()

    await expect(page).toHaveURL(/\/portal(\/|$)/, { timeout: 20_000 })
  })

  /** The URL grants nothing: an advertiser session cannot walk into the agency console. */
  test('an app session cannot reach /agency or /admin by typing the address', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, 'advertiser@campaignshub.io')
    await page.getByTestId('login-code').locator('button[type="submit"]').click()
    await expect(page).toHaveURL(/\/app\//, { timeout: 20_000 })

    /*
     * Refused, and told so — the product's own refusal surface rather than a guess about the URL.
     *
     * A portal that denies access may legitimately keep the address in the bar and render a refusal
     * on it, which is what `agency-portal-denied` is for. Asserting on the URL alone would call that
     * a failure, and would equally pass for a console that rendered its contents under a redirect.
     * What must never happen is the agency console appearing.
     */
    await page.goto('/agency/clients')
    await expect(page.getByTestId('agency-portal-denied')).toBeVisible({ timeout: 20_000 })

    await page.goto('/admin')
    await expect(page.getByText(/لوحة المنصة|Platform overview/)).toHaveCount(0)

    // The session survived being refused — a refusal is not a sign-out.
    expect(await whoAmI(page)).toBe('advertiser@campaignshub.io')
  })

  /**
   * Out, then in again — through the button a customer actually presses.
   *
   * ## Why this no longer forges its own logout request
   *
   * It used to `page.request.post('/auth/logout', …)` with an `X-XSRF-TOKEN` read out of the cookie
   * jar, and **never asserted the response**. When that request was refused — a 419 on a token that
   * had lagged a session regeneration — the session correctly survived and this test announced «the
   * session outlived the sign-out». It accused the product of a security defect when the truth was
   * that the sign-out had never been accepted, and it was the loudest recurring failure in the gate.
   *
   * The server was measured directly at the HTTP layer and is not defective: sign in, `/auth/me`
   * 200, `/auth/logout` 200, `/auth/me` 401 on the same cookie jar. So the fix belongs here, and the
   * honest fix is to stop hand-rolling the request at all: press the menu item the customer presses,
   * and let the application send its own CSRF token exactly as it does in production. A forged
   * request can only ever test the forgery.
   *
   * `whoAmI` still asks the SERVER afterwards, through the same browser context, because a redirect
   * to `/login` would pass for a page that merely navigated.
   *
   * ## What was actually wrong — closed 2026-08-11 (`ACCESS-EXIT-003`)
   *
   * This test was right and the product was wrong, in a way that took several overturned hypotheses
   * to see. Instrumentation showed the UI sign-out issuing exactly one `/auth/logout`, returning
   * **200**, with the server separately proven to destroy the session on that 200 — and `whoAmI`
   * still naming the user afterwards on chromium and firefox, but not on webkit.
   *
   * The session was not surviving the sign-out. It was being **put back**. A request that loaded the
   * authenticated session before the click writes that payload to the store when it finishes, under
   * the same id, after `logout` deleted it. Measured byte for byte against the running server:
   * restore the 287 bytes under the deleted key and the same cookie jar answers 200 again.
   *
   * Both halves of the fix are needed and neither is a workaround. The client refuses to send new
   * authenticated requests once sign-out begins (`ACCESS-EXIT-002`), which removes most of the
   * window; the server records the signed-out session id in a marker that lives outside the session,
   * so a request already in flight cannot hand the credential back (`ACCESS-EXIT-003`). The browser
   * asymmetry was timing, not behaviour — webkit simply had fewer requests in flight at the click.
   */
  test('signing out ends the session, and a new code opens another', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, 'advertiser@campaignshub.io')
    await page.getByTestId('login-code').locator('button[type="submit"]').click()
    await expect(page).toHaveURL(/\/app\//, { timeout: 20_000 })
    expect(await whoAmI(page), 'nothing to sign out of — the session never opened').toBe('advertiser@campaignshub.io')

    await page.getByRole('button', { name: /User menu|قائمة المستخدم/ }).first().click()
    await page.getByRole('menuitem', { name: /Sign out|تسجيل الخروج/ }).click()

    // The app takes itself back to the sign-in page; that is the observable end of the journey.
    await expect(page).toHaveURL(/\/login/, { timeout: 20_000 })

    expect(await whoAmI(page), 'the session outlived the sign-out').toBeNull()
  })
})

test.describe('what the code step does when things go wrong', () => {
  test('a wrong code is refused, in the same design, and signs nobody in', async ({ page }) => {
    await page.goto('/login')
    await switchToEnglish(page)
    await requestCode(page, 'wrong-code-probe@example.test')

    // Clear the pre-filled code and type six digits that are not it.
    for (let i = 5; i >= 0; i--) {
      await page.getByTestId(`login-otp-${i}`).focus()
      await page.keyboard.press('Backspace')
    }
    await page.getByTestId('login-otp-0').focus()
    await page.keyboard.type('000000')

    await page.getByTestId('login-code').locator('button[type="submit"]').click()

    await expect(page.getByTestId('login-error')).toBeVisible({ timeout: 20_000 })
    await expect(page).toHaveURL(/\/login/)
    expect(await whoAmI(page)).toBeNull()
  })

  /** The resend is closed while the server's own window is closed. */
  test('the resend is disabled and counts down', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, 'cooldown-probe@example.test')

    const resend = page.getByTestId('login-resend')
    await expect(resend).toBeDisabled()
    await expect(resend).toHaveText(/\d+/)
  })

  /** A mistyped address is correctable without a reload, and the card comes back intact. */
  test('changing the address returns to the card', async ({ page }) => {
    await page.goto('/login')
    await requestCode(page, 'typo@demo-company.local')

    await page.getByTestId('login-change-identifier').click()

    await expect(page.getByTestId('login-identify')).toBeVisible()
    await expect(page.getByTestId('login-email')).toHaveValue('typo@demo-company.local')
    await expect(page.getByTestId('login-code')).toHaveCount(0)
  })

  /**
   * An address nobody holds is answered exactly like one somebody does, and then goes nowhere.
   *
   * Both halves matter. Refusing at the first step would tell a stranger which addresses have
   * accounts here; signing them in would be worse.
   */
  test('an unknown address gets the same step, and signs nobody in', async ({ page }) => {
    await page.goto('/login')
    await switchToEnglish(page)
    await requestCode(page, 'nobody-at-all@example.test')

    await page.getByTestId('login-code').locator('button[type="submit"]').click()

    await expect(page.getByTestId('login-error')).toBeVisible({ timeout: 20_000 })
    expect(await whoAmI(page)).toBeNull()
  })

  /**
   * No mail provider is configured on this environment, so the page says so.
   *
   * This is READY_FOR_CREDENTIALS made visible: the flow works end to end, and the product does not
   * claim a message reached anybody. If a provider is ever wired here the notice disappears on its
   * own, so this asserts the pair rather than the absence.
   */
  test('the page states plainly whether the code was actually sent', async ({ page }) => {
    await page.goto('/login')

    /*
     * The claim is a BICONDITIONAL, checked against what the server actually said.
     *
     * «The notice is visible» on its own only holds while this environment has no mail provider, and
     * would have to be edited the day one is wired — which is how a test quietly stops meaning
     * anything. What must be true either way is that the page warns exactly when the delivery state
     * is not one that means «it arrived», and stays quiet exactly when it is.
     */
    const answered = page.waitForResponse((r) => r.url().includes('/auth/email-code/start') && r.status() === 200)
    await requestCode(page, 'delivery-probe@example.test')
    const state = String((await (await answered).json()).data.delivery_status)

    /*
     * No mail provider is configured on this environment, so the notice is expected here — and it is
     * asserted as a PAIR rather than as a bare presence: either the product says nothing arrived, or
     * it says nothing at all about delivery, and the second is only allowed when something really is
     * configured. That way wiring a provider makes this test change meaning honestly instead of
     * failing, and removing the notice while still delivering nothing fails it.
     */
    const shown = await page.getByTestId('login-code-undelivered').count() > 0

    expect(
      shown,
      `the page ${shown ? 'warned about' : 'said nothing about'} delivery while the server reported «${state}»`,
    ).toBe(!['sent', 'queued', 'delivered'].includes(state))
  })
})
