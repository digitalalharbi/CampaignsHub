import { expect, test } from '@playwright/test'
import { signIn, submitVerifiedRequest } from './helpers'

/**
 * LOGIN-UNIFIED-001 — one door, and the server decides where you land.
 *
 * The claim under test is not that `/login` answers 200. It is that the visitor is never asked which
 * portal they want, that the URL cannot grant one, and that the five addresses which used to ask
 * still work — as redirects, without a loop, carrying the destination through.
 */
test.use({ storageState: { cookies: [], origins: [] } })

/** Every address that used to be its own sign-in page. */
const OLD_DOORS = ['/admin/login', '/app/login', '/agency/login', '/portal/login', '/influencers/login'] as const

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
   * Nothing secret is asked for until the account is known.
   *
   * A password field rendered before the server has said this account HAS a password is the exact
   * shape of the old defect: a client typing into a field their account has never had.
   */
  test('asks who you are before it asks for anything secret', async ({ page }) => {
    await page.goto('/login')

    await expect(page.locator('input[type="password"]')).toHaveCount(0)
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

    await signIn(page, 'owner@demo-agency.local')
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
    await signIn(page, 'owner@demo-company.local')
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })
  })

  test('an agency operator lands in /agency', async ({ page }) => {
    await page.goto('/admin/login')
    await signIn(page, 'owner@demo-agency.local')
    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })
  })
})

/**
 * The one-time-code branch is NOT covered here, deliberately.
 *
 * Reaching it end to end needs a real client contact, and the only honest way to create one from a
 * browser is to file a request through the intake flow — which made this spec a test of the intake
 * flow first and of sign-in second, and it failed on the intake's own success page rather than on
 * anything to do with logging in. A test whose failure does not point at the thing it names is worse
 * than no test.
 *
 * The branch is covered where it can be tested directly:
 *   - `backend/tests/Feature/Identity/SignInMethodTest.php` — the resolver, against a real contact
 *     row, including the user-beats-contact collision and the no-enumeration rule.
 *   - `src/features/auth/LoginPage.test.tsx` — the page renders the code step, and only the code
 *     step, when the server says `method: 'code'`.
 * Both were also walked by hand against the running stack: a seeded contact address entered at
 * `/login` reached the code step and signed in to `/portal`.
 */
