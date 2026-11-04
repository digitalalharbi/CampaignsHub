import { expect, test, type Page } from '@playwright/test'
import { enableEmailCode, openEmailCode, signIn, signInAsContact, switchToEnglish, DEMO_CLIENT_CONTACT } from './helpers'

/**
 * LOGIN-SINGLE-DOOR-001 — sign-in journeys that end by asking the SERVER, since a flow can look finished while being hollow; and the
 * code step, which the server keeps off by default. The gate's server runs that default, so the step is walked against stated answers;
 * what the engine itself does when on (delivery, single use, a disabled account, enumeration, audit) is `PortalEmailSignInTest`.
 */
test.use({ storageState: { cookies: [], origins: [] } })

/** The session, as the SERVER sees it. `null` when there is none. */
async function whoAmI(page: Page): Promise<string | null> {
  const res = await page.request.get('/api/v1/auth/me', {
    headers: { Accept: 'application/json', Origin: new URL(page.url()).origin },
  })

  if (!res.ok()) return null

  return (await res.json()).data?.user?.email ?? null
}

test.describe('a session the server recognises', () => {
  /** A client contact types the same two fields as everybody else, and the server sends them to the portal. */
  test('a client contact reaches /portal through the one door, and the server knows them', async ({ page }) => {
    await signInAsContact(page, DEMO_CLIENT_CONTACT)

    await expect(page).toHaveURL(/\/portal(\/(?!login)|$)/, { timeout: 20_000 })
    expect(await whoAmI(page)).toBe(DEMO_CLIENT_CONTACT)
  })

  /** The URL grants nothing: an advertiser session cannot walk into the agency console. */
  test('an app session cannot reach /agency or /admin by typing the address', async ({ page }) => {
    await signIn(page, 'advertiser@campaignshub.io')
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
  test('signing out ends the session, and a new sign-in opens another', async ({ page }) => {
    await signIn(page, 'advertiser@campaignshub.io')
    await expect(page).toHaveURL(/\/app\//, { timeout: 20_000 })
    expect(await whoAmI(page), 'nothing to sign out of — the session never opened').toBe('advertiser@campaignshub.io')

    await page.getByRole('button', { name: /User menu|قائمة المستخدم/ }).first().click()
    await page.getByRole('menuitem', { name: /Sign out|تسجيل الخروج/ }).click()

    // The app takes itself back to the sign-in page; that is the observable end of the journey.
    await expect(page).toHaveURL(/\/login/, { timeout: 20_000 })

    expect(await whoAmI(page), 'the session outlived the sign-out').toBeNull()
  })
})

// The same address twice in one browser, as somebody who signs in again does: the second press must send it.
test('the same address asked for again still sends on the first press', async ({ page, browser }) => {
  await enableEmailCode(page)
  await page.goto('/login')
  await openEmailCode(page, 'twice-probe@example.test')

  const second = await (await browser.newContext({ storageState: { cookies: [], origins: [] } })).newPage()
  await enableEmailCode(second)
  await second.goto('/login')
  await second.getByTestId('login-email-code').click()
  const sent = second.waitForRequest((r) => r.url().includes('/client/login/start'), { timeout: 10_000 })
  await second.getByTestId('email-code-address').fill('twice-probe@example.test')
  await second.getByTestId('email-code-send').click()
  await sent
  await second.context().close()
})

test.describe('what the code step does when things go wrong', () => {
  test('a wrong code is refused, in the same design, and signs nobody in', async ({ page }) => {
    await enableEmailCode(page, { verify: { status: 422, json: { message: 'The code is not correct.', errors: { code: ['The code is not correct.'] } } } })
    await page.goto('/login')
    await switchToEnglish(page)
    await openEmailCode(page, 'wrong-code-probe@example.test')

    await page.getByTestId('email-code-step').locator('button[type="submit"]').click()

    await expect(page.getByTestId('login-error')).toBeVisible({ timeout: 20_000 })
    await expect(page).toHaveURL(/\/login/)
    expect(await whoAmI(page)).toBeNull()
  })

  /** The resend is closed while the server's own window is closed. */
  test('the resend is disabled and counts down', async ({ page }) => {
    await enableEmailCode(page)
    await page.goto('/login')
    await openEmailCode(page, 'cooldown-probe@example.test')

    const resend = page.getByTestId('email-code-resend')
    await expect(resend).toBeDisabled()
    await expect(resend).toHaveText(/\d+/)
  })

  /** A mistyped address is correctable without a reload, and the card comes back intact. */
  test('changing the address returns to the card', async ({ page }) => {
    await enableEmailCode(page)
    await page.goto('/login')
    await openEmailCode(page, 'typo@example.test')

    await page.getByTestId('email-code-change').click()

    await expect(page.getByTestId('email-code-form')).toBeVisible()
    await expect(page.getByTestId('email-code-address')).toHaveValue('typo@example.test')
    await expect(page.getByTestId('email-code-step')).toHaveCount(0)
  })

  /** Warned exactly when the delivery state is not one that means «it arrived», and quiet exactly when it is. */
  for (const [state, warns] of [['awaiting_credentials', true], ['failed', true], ['sandbox', true], ['sent', false]] as const) {
    test(`with delivery «${state}» the page ${warns ? 'says nothing arrived' : 'says nothing about delivery'}`, async ({ page }) => {
      await enableEmailCode(page, { delivery: state })
      await page.goto('/login')
      await openEmailCode(page, 'delivery-probe@example.test')

      await expect(page.getByTestId('email-code-undelivered')).toHaveCount(warns ? 1 : 0)
    })
  }
})

// Off is what the gate's real server runs: the code's addresses answer nothing, and the page never offers it.
test('with the code off, its routes answer 404 and /login offers nothing', async ({ page }) => {
  await page.goto('/login')
  await expect(page.getByTestId('login-email-code')).toHaveCount(0)

  const res = await page.request.post('/api/v1/client/login/start', {
    headers: { Accept: 'application/json', Origin: new URL(page.url()).origin },
    data: { channel: 'email', destination: 'probe@example.test' },
  })
  expect([404, 419]).toContain(res.status())
})
