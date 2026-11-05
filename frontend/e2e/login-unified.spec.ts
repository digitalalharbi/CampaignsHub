import { expect, test } from '@playwright/test'
import { CONTACT_PASSWORD, DEMO_CLIENT_CONTACT, chooseContactPassword, enableEmailCode, openEmailCode, signIn, signInAsContact } from './helpers'

/**
 * LOGIN-UNIFIED-001 — one door, and the server decides where you land.
 *
 * The claim under test is not that `/login` answers 200. It is that the visitor is never asked which
 * portal they want, that the URL cannot grant one, and that the five addresses which used to ask
 * still work — as redirects, without a loop, carrying the destination through.
 */
test.use({ storageState: { cookies: [], origins: [] } })

/** Every address that used to be its own sign-in page. */
const OLD_DOORS = ['/admin/login', '/app/login', '/agency/login', '/influencers/login', '/portal/login', '/client/login'] as const

test.describe('the portal chooser is gone', () => {
  test('the sign-in page offers no portal to choose', async ({ page }) => {
    await page.goto('/login')

    await expect(page.getByTestId('login-identify')).toBeVisible()
    await expect(page.getByTestId('login-portals')).toHaveCount(0)
    for (const key of ['default', 'agency', 'client', 'influencer', 'admin']) {
      await expect(page.getByTestId(`login-portal-${key}`)).toHaveCount(0)
    }
  })

  /**
   * The card asks for credentials; it does not ask for a code nobody has requested.
   *
   * The code step is reached by ASKING for one, and a six-box field sitting there on arrival would
   * be a form waiting for something that was never sent.
   */
  test('asks for credentials, and for no code nobody requested', async ({ page }) => {
    await page.goto('/login')

    await expect(page.getByTestId('login-email')).toBeVisible()
    await expect(page.getByTestId('login-code')).toHaveCount(0)
  })
})

test.describe('the old doors', () => {
  for (const door of OLD_DOORS) {
    test(`${door} redirects to /login`, async ({ page }) => {
      await page.goto(door)
      await expect(page).toHaveURL(/\/login(\?|$)/)
      await expect(page.getByTestId('login-identify')).toBeVisible()
    })
  }

  // LOGIN-SINGLE-DOOR-001 — the code step is off by default, so an old door shows the same page as any other.
  test('/portal/login shows the one page, with a password field and no client portal link', async ({ page }) => {
    await page.goto('/portal/login?redirect=%2Fportal%2Frequests')
    await expect(page).toHaveURL(/\/login\?redirect=%2Fportal%2Frequests/)
    await expect(page.getByTestId('login-password')).toBeVisible()
    await expect(page.getByTestId('login-client-door')).toHaveCount(0)
    await expect(page.getByTestId('login-email-code')).toHaveCount(0)
    await expect(page.getByText(/بوابة العملاء|client portal/i)).toHaveCount(0)
  })

  /**
   * Back must work.
   *
   * With a push instead of a replace, Back from `/login` returns to `/app/login`, which redirects
   * forward again — the visitor presses the one control they reached for and nothing happens.
   */
  test('Back from a redirected door does not bounce forward again', async ({ page }) => {
    await page.goto('/')
    await page.goto('/agency/login')
    await expect(page).toHaveURL(/\/login/)

    await page.goBack()
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('the destination survives the redirect and the sign-in', async ({ page }) => {
    await page.goto('/agency/login?redirect=%2Fagency%2Fclients')
    await expect(page).toHaveURL(/\/login\?redirect=%2Fagency%2Fclients/)

    await signIn(page, 'agency@campaignshub.io')
    await expect(page).toHaveURL(/\/agency\/clients/, { timeout: 20000 })
  })
})

/**
 * The property the whole change turns on.
 *
 * Both accounts sign in from the SAME URL — one that used to name the platform console — and land
 * in different portals, neither of them `/admin`. The address grants nothing; memberships decide.
 */
test.describe('the server picks the portal, not the URL', () => {
  test('an advertiser lands in /app', async ({ page }) => {
    await page.goto('/admin/login')
    await signIn(page, 'advertiser@campaignshub.io')
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })
  })

  test('an agency operator lands in /agency', async ({ page }) => {
    await page.goto('/admin/login')
    await signIn(page, 'agency@campaignshub.io')
    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })
  })
})

test.describe('LOGIN-SINGLE-DOOR-001 — the destination is the server\'s', () => {
  test('a platform admin lands in the console', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, 'admin@campaignshub.io')
    await expect(page).toHaveURL(/\/admin(\/|$)/, { timeout: 20000 })
  })

  test('a client-portal member lands in the portal, with no 401 straight after signing in', async ({ page }) => {
    const refused: string[] = []
    page.on('response', (r) => { if (r.status() === 401 && r.url().includes('/api/')) refused.push(r.url()) })
    await page.goto('/login')
    await page.waitForLoadState('networkidle')
    refused.length = 0

    await signIn(page, 'client@campaignshub.io')
    await expect(page).toHaveURL(/\/portal(\/|$)/, { timeout: 20000 })
    await page.waitForLoadState('networkidle')
    expect(refused).toEqual([])
  })

  for (const [label, redirect] of [
    ['an address on another site', 'https%3A%2F%2Fevil.example%2Fx'],
    ['a protocol-relative address', '%2F%2Fevil.example'],
    ['a portal this role cannot enter', '%2Fagency%2Fclients'],
    ['the console', '%2Fadmin'],
    ['the sign-in page itself', '%2Flogin'],
    ['an old door', '%2Fportal%2Flogin'],
  ]) {
    test(`an advertiser asked to go to ${label} lands in their own portal`, async ({ page }) => {
      await page.goto(`/login?redirect=${redirect}`)
      await signIn(page, 'advertiser@campaignshub.io')
      await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })
      await expect(page.getByTestId('login-identify')).toHaveCount(0)
    })
  }

  test('a page in the person\'s own portal is kept', async ({ page }) => {
    await page.goto('/login?redirect=%2Fapp%2Freports')
    await signIn(page, 'advertiser@campaignshub.io')
    await expect(page).toHaveURL(/\/app\/reports/, { timeout: 20000 })
  })

  test('signed in already, /login moves on and does not bounce back', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, 'advertiser@campaignshub.io')
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })

    await page.goto('/login?redirect=%2Fportal%2Frequests')
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })
  })

  test('an expired session comes back to /login with the page kept, and returns there', async ({ page, context }) => {
    await page.goto('/login')
    await signIn(page, 'client@campaignshub.io')
    await expect(page).toHaveURL(/\/portal(\/|$)/, { timeout: 20000 })

    // Settled first: a response still in the air when the jar is cleared writes the session cookie straight back.
    await page.waitForLoadState('networkidle')
    await context.clearCookies()
    expect(await context.cookies()).toEqual([])
    await page.goto('/portal/requests')
    await expect(page).toHaveURL(/\/login\?redirect=%2Fportal%2Frequests/, { timeout: 20000 })

    await signIn(page, 'client@campaignshub.io')
    await expect(page).toHaveURL(/\/portal\/requests/, { timeout: 20000 })
  })

  test('signing out of the portal leads to the one page, and it stays there', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, 'client@campaignshub.io')
    await expect(page).toHaveURL(/\/portal(\/|$)/, { timeout: 20000 })

    await page.getByRole('button', { name: /Sign out|خروج/ }).first().click()
    await expect(page).toHaveURL(/\/login/, { timeout: 20000 })
    await expect(page.getByTestId('login-identify')).toBeVisible()
    await page.waitForLoadState('networkidle')
    await expect(page).toHaveURL(/\/login/)
  })

  test('recovery is one page for everybody and says the same for anybody', async ({ page }) => {
    await page.goto('/forgot-password')
    await page.locator('input[type="email"]').fill('nobody-at-all@example.test')
    await page.locator('form button[type="submit"]').click()
    await expect(page.locator('input[type="email"]')).toHaveCount(0, { timeout: 20000 })
    await expect(page.locator('a[href="/login"]').first()).toBeVisible()
  })
})

/**
 * A client contact with no password, by the way they actually come in.
 *
 * The contact comes from the demo seed rather than from filing a request through the intake flow; `client-portal.spec.ts` covers the
 * guest-to-portal journey. The resolver of the destination is covered in `LoginSingleDoorTest`; only the browser proves the whole path.
 */
test.describe('a client contact with no password', () => {
  test('cannot get in with a guess, and is told nothing about the address', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, 'never-named@example.test', 'not-a-password')
    await expect(page.getByTestId('login-identify')).toBeVisible()
    await expect(page).toHaveURL(/\/login/)
  })

  test('chooses a password through recovery and lands in /portal', async ({ page }) => {
    await signInAsContact(page, DEMO_CLIENT_CONTACT)

    /*
     * Inside the portal — but WHERE inside it is the portal's business, not this test's: a contact named on one space goes straight in,
     * one on several gets the picker, and how many this seed has depends on what the suite created earlier.
     */
    await expect(page).toHaveURL(/\/portal(\/(?!login)|$)/, { timeout: 20000 })
  })

  test('the session survives a reload and a direct link', async ({ page }) => {
    await signInAsContact(page, DEMO_CLIENT_CONTACT)
    await expect(page).toHaveURL(/\/portal(\/(?!login)|$)/, { timeout: 20000 })

    await page.reload()
    await expect(page).toHaveURL(/\/portal(\/(?!login)|$)/)

    await page.goto('/portal')
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('a link into the portal returns them to that page', async ({ page }) => {
    await chooseContactPassword(page, DEMO_CLIENT_CONTACT)
    await page.goto('/portal/login?redirect=%2Fportal%2Frequests')
    await expect(page).toHaveURL(/\/login\?redirect=%2Fportal%2Frequests/)

    await signIn(page, DEMO_CLIENT_CONTACT, CONTACT_PASSWORD)
    await expect(page).toHaveURL(/\/portal\/requests/, { timeout: 20000 })
  })
})

test.describe('the email code, when the server enables it', () => {
  test('is a step of /login, and the server names where it leads', async ({ page }) => {
    let sent: Record<string, unknown> = {}
    await enableEmailCode(page, { onVerify: (body) => { sent = body } })
    await page.goto('/login?redirect=%2Fportal%2Frequests')
    await openEmailCode(page, DEMO_CLIENT_CONTACT)
    await expect(page.locator('input[type="password"]')).toHaveCount(0)

    await page.getByTestId('email-code-step').locator('button[type="submit"]').click()

    await expect(page).toHaveURL(/portal/, { timeout: 20000 })
    expect(sent.redirect).toBe('/portal/requests')
  })
})
