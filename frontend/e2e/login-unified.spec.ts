import { expect, test } from '@playwright/test'
import { DEMO_CLIENT_CONTACT, signIn, signInWithCode } from './helpers'

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

/**
 * The one-time-code half of the same door.
 *
 * A client contact has never had a password. Before LOGIN-UNIFIED-001 they had their own page; now
 * they type the same field as everybody else and the server sends them down the other branch. That
 * hand-off is the part worth testing end to end — the resolver is covered in isolation by
 * `backend/tests/Feature/Identity/SignInMethodTest.php`, but only the browser proves that answering
 * `code` actually results in a session and a portal.
 *
 * The contact comes from the demo seed rather than from filing a request through the intake flow.
 * Driving intake here made the spec fail on the intake's own success page, which points away from
 * the thing this test names; `client-portal.spec.ts` covers the guest-to-portal journey properly.
 */
test.describe('the code branch of the same door', () => {
  test('a client contact is offered a code, not a password', async ({ page }) => {
    await page.goto('/login')
    await page.getByTestId('login-email').fill(DEMO_CLIENT_CONTACT)
    await page.getByTestId('login-request-code').click()

    await expect(page.getByTestId('login-code')).toBeVisible({ timeout: 20000 })
    // The card it came from is gone, password field and all — one step at a time.
    await expect(page.getByTestId('login-password')).toHaveCount(0)
    await expect(page.locator('input[type="password"]')).toHaveCount(0)
  })

  test('a client contact signs in with the code and lands in /portal', async ({ page }) => {
    await signInWithCode(page, DEMO_CLIENT_CONTACT)

    /*
     * Inside the portal — but WHERE inside it is the portal's business, not this test's.
     *
     * A contact named on exactly one client space is taken straight into it; one named on several is
     * shown the picker at `/portal/spaces` (PORTAL-CLIENT-001). How many spaces the seeded contact
     * has depends on what the rest of the suite has created before this point, and pinning that made
     * this fail on the second browser of a full run for a reason that had nothing to do with signing
     * in. The claim under test is that a one-time code produces a portal session.
     */
    await expect(page).toHaveURL(/\/portal(\/|$)/, { timeout: 20000 })
    await expect(page.getByTestId('login-code')).toHaveCount(0)
  })

  test('the code session survives a reload and a direct link', async ({ page }) => {
    await signInWithCode(page, DEMO_CLIENT_CONTACT)
    await expect(page).toHaveURL(/\/portal/, { timeout: 20000 })

    await page.reload()
    await expect(page).toHaveURL(/\/portal/)
    await expect(page).not.toHaveURL(/\/login/)

    await page.goto('/portal')
    await expect(page).not.toHaveURL(/\/login/)
  })

  test('a contact who is bounced to the door comes back to where they were going', async ({ page }) => {
    await page.goto('/portal/login?redirect=%2Fportal%2Frequests')
    await expect(page).toHaveURL(/\/login\?redirect=/)

    await signInWithCode(page, DEMO_CLIENT_CONTACT)
    await expect(page).toHaveURL(/\/portal\/requests/, { timeout: 20000 })
  })
})
