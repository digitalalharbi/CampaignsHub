import { expect, test, type Page, type Route } from '@playwright/test'

/**
 * LOGIN-004 — «ربط Google»: Google answered with an address that matches an account, and the page asks for proof instead of ending.
 *
 * The provider leg cannot run here (no Google), so — like `login-stable.spec.ts` — the API answers are the test's own. What this proves is
 * the page: the card replaces the form, shows who is connecting and which account it matches, takes the password or the mailed link, ends
 * where the server says, and never puts the profile in the address bar. The server half (proof first, link second, single use, ten
 * minutes, the shared throttle, suspended accounts) is `GoogleLinkInPlaceTest`, over real HTTP.
 *
 * Set `GOOGLE_LINK_SHOTS=/some/dir` to also write the screenshots a reviewer reads (dark and light, desktop and 375px).
 */
test.use({ storageState: { cookies: [], origins: [] } })

const PENDING = { provider: 'google', name: 'Mohammed G', email: 'owner@example.com', avatar: null, local_email: 'ow•••@example.com', expires_in: 600, email_sent: false, can_email: true }
const ok = (data: unknown) => ({ json: { success: true, message: 'ok', data, meta: {}, errors: null } })
const refuse = (status: number, body: Record<string, unknown>) => ({ status, json: { success: false, message: 'refused', data: null, errors: null, meta: {}, ...body } })

async function stubProviders(page: Page, onStart?: (route: Route) => void) {
  await page.route('**/auth/oauth/providers', (route) => route.fulfill(ok({ email_code: false, providers: [{ provider: 'google', label: { en: 'Google', ar: 'Google' }, status: 'live', available: true }] })))
  await page.route('**/auth/oauth/google/start', (route) => {
    onStart?.(route)
    // The provider's trip, collapsed: Google "returns" to the sign-in page with the flag the real callback redirects with.
    return route.fulfill(ok({ authorize_url: new URL('/login?oauth=link', page.url()).toString() }))
  })
}

const shots = process.env.GOOGLE_LINK_SHOTS

for (const [width, height, label] of [[1366, 768, 'desktop'], [375, 812, 'phone']] as const) {
  for (const theme of ['dark', 'light'] as const) {
    test(`${label} ${theme}: the card shows who is connecting and offers both proofs`, async ({ page }) => {
      await page.setViewportSize({ width, height })
      await page.addInitScript(([t]) => { localStorage.setItem('campaign-hub-theme', t); localStorage.setItem('campaign-hub-locale', 'ar') }, [theme])
      await stubProviders(page)
      await page.route('**/auth/oauth/pending-link', (route) => route.fulfill(ok(PENDING)))

      await page.goto('/login')
      await page.getByTestId('login-google').click()

      const card = page.getByTestId('oauth-link-card')
      await expect(card).toBeVisible()
      await expect(page).toHaveURL(/\/login\?oauth=link$/)
      await expect(page.getByTestId('oauth-link-google')).toContainText('Mohammed G')
      await expect(page.getByTestId('oauth-link-google')).toContainText('owner@example.com')
      await expect(page.getByTestId('oauth-link-local')).toContainText('ow•••@example.com')
      await expect(page.getByTestId('login-identify')).toHaveCount(0)
      await expect(page.getByTestId('oauth-link-send')).toBeVisible()
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
      // Nothing from Google reached the address bar.
      expect(page.url()).not.toMatch(/owner|Mohammed|sub-/)

      if (shots) await page.screenshot({ path: `${shots}/link-${label}-${theme}-idle.png`, fullPage: true })
    })
  }
}

test('the right password links, signs in and lands where the server says', async ({ page }) => {
  const bodies: unknown[] = []
  await stubProviders(page)
  await page.route('**/auth/oauth/pending-link', (route) => route.fulfill(ok(PENDING)))
  await page.route('**/auth/oauth/pending-link/password', (route) => {
    bodies.push(route.request().postDataJSON())

    return route.fulfill(ok({ user: { id: 'u1', name: 'Owner', email: 'owner@example.com' }, destination: '/privacy' }))
  })

  await page.goto('/login?oauth=link')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('correct-horse-battery')
  await page.getByTestId('oauth-link-submit').click()

  await expect(page).toHaveURL(/\/privacy$/)
  expect(bodies).toEqual([{ password: 'correct-horse-battery' }])
})

test('a wrong password says so on the field and keeps the card', async ({ page }) => {
  await stubProviders(page)
  await page.route('**/auth/oauth/pending-link', (route) => route.fulfill(ok(PENDING)))
  await page.route('**/auth/oauth/pending-link/password', (route) => route.fulfill(refuse(422, { errors: { password: ['These credentials do not match.'] } })))

  await page.addInitScript(() => localStorage.setItem('campaign-hub-locale', 'ar'))
  await page.goto('/login?oauth=link')
  await page.getByLabel('كلمة المرور', { exact: true }).fill('nope-nope')
  await page.getByTestId('oauth-link-submit').click()

  await expect(page.getByText('كلمة المرور غير صحيحة.')).toBeVisible()
  await expect(page.getByTestId('oauth-link-card')).toBeVisible()
  if (shots) await page.screenshot({ path: `${shots}/link-desktop-wrong.png`, fullPage: true })
})

test('the mailed link: send, then spend it once; the secret leaves the address bar', async ({ page }) => {
  const confirmed: unknown[] = []
  await stubProviders(page)
  await page.route('**/auth/oauth/pending-link', (route) => route.fulfill(ok(PENDING)))
  await page.route('**/auth/oauth/pending-link/email', (route) => route.fulfill(ok({ delivery: 'sent', resend_after: 60 })))
  await page.route('**/auth/oauth/pending-link/confirm-email', (route) => {
    confirmed.push(route.request().postDataJSON())

    return route.fulfill(ok({ user: { id: 'u1', name: 'Owner', email: 'owner@example.com' }, destination: '/privacy' }))
  })

  await page.addInitScript(() => localStorage.setItem('campaign-hub-locale', 'ar'))
  await page.goto('/login?oauth=link')
  await page.getByTestId('oauth-link-send').click()
  await expect(page.getByTestId('oauth-link-sent')).toContainText('ow•••@example.com')
  if (shots) await page.screenshot({ path: `${shots}/link-desktop-sent.png`, fullPage: true })

  // The link in the message, opened in the same browser.
  await page.goto('/login?oauth=link&confirm=secret-from-mail')
  await expect(page).toHaveURL(/\/privacy$/)
  expect(confirmed).toEqual([{ token: 'secret-from-mail' }])
})

test('the mailed link opened elsewhere fails closed with the expired state, and leads back', async ({ page }) => {
  await stubProviders(page)
  await page.route('**/auth/oauth/pending-link', (route) => route.fulfill(refuse(410, { meta: { state: 'expired' } })))
  await page.route('**/auth/oauth/pending-link/confirm-email', (route) => route.fulfill(refuse(410, { meta: { state: 'expired' } })))

  await page.addInitScript(() => localStorage.setItem('campaign-hub-locale', 'ar'))
  await page.goto('/login?oauth=link&confirm=secret-from-mail')

  await expect(page.getByTestId('oauth-link-expired')).toBeVisible()
  await expect(page).not.toHaveURL(/confirm=/)
  if (shots) await page.screenshot({ path: `${shots}/link-desktop-expired.png`, fullPage: true })

  await page.getByTestId('oauth-link-back').click()
  await expect(page.getByTestId('login-identify')).toBeVisible()
})

test('cancel discards and returns to the ordinary form', async ({ page }) => {
  let cancelled = 0
  await stubProviders(page)
  await page.route('**/auth/oauth/pending-link', (route) => {
    if (route.request().method() === 'DELETE') { cancelled += 1; return route.fulfill(ok(null)) }

    return route.fulfill(ok(PENDING))
  })

  await page.goto('/login?oauth=link')
  await page.getByTestId('oauth-link-cancel').click()

  await expect(page.getByTestId('login-identify')).toBeVisible()
  expect(cancelled).toBe(1)
})
