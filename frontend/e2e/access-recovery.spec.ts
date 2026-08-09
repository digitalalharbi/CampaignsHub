import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * ACCESS-EXIT-001 — no screen may refuse somebody without also offering a way out.
 *
 * The defect these lock down was not a wrong message, it was a **trap**: a refusal offered one button,
 * and for somebody holding no membership that button pointed at `/switch`, which said «no workspace
 * yet» and offered nothing at all. The session stayed valid, so closing the tab and returning landed
 * on the same wall. The only escape was clearing site data by hand.
 *
 * Every test here therefore asserts on the ACTIONS, not on the wording. A refusal whose copy is
 * rewritten should keep passing; a refusal that loses its exits must not.
 */


/**
 * Sign in as the seeded account that belongs to NOTHING (`DemoAccountsSeeder`).
 *
 * Through the API rather than the form: this suite is about what happens AFTER a valid session
 * exists, and driving the login form here would make every one of these tests also a test of the
 * login page. The account is seeded rather than created ad hoc so the state is reproducible on any
 * install — a state nobody can reach is a state nobody checks, which is how this trap survived.
 */
async function signInAs(page: Page, email: string): Promise<void> {
  /*
   * Through the FORM, not a hand-rolled fetch.
   *
   * The fetch version primed its own CSRF cookie beside the one the app had already taken, and the
   * server answered 419 — a failure that says nothing about the thing under test. Driving the real
   * form uses the app's own token handling, which is the only version guaranteed to stay correct when
   * that handling changes.
   */
  // Two steps (LOGIN-UNIFIED-001): identify, then the form the SERVER says this account uses.
  await page.goto('/login')
  await page.getByTestId('login-identify').locator('input').fill(email)
  await page.getByTestId('login-identify').locator('button[type="submit"]').click()
  await expect(page.getByTestId('login-password')).toBeVisible({ timeout: 20000 })
  await page.getByTestId('login-password').locator('input[type="password"]').fill('password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
  await expect(page, `${email} could not sign in`).not.toHaveURL(/\/login$/, { timeout: 20000 })
}

const signInAsNoWorkspace = (page: Page) => signInAs(page, 'no-workspace@demo.local')

/** The four things that must always be reachable from a dead end. */
const alwaysAvailable = ['recovery-switch-account', 'recovery-home', 'recovery-sign-out']

async function actionsOn(page: Page): Promise<string[]> {
  await expect(page.getByTestId('access-recovery')).toBeVisible({ timeout: 20000 })
  return page.locator('[data-testid^="recovery-"]').evaluateAll((els) =>
    els.map((e) => (e as HTMLElement).dataset.testid ?? ''),
  )
}

test.describe('an advertiser who opens the agency portal', () => {
  test.use({ storageState: AUTH.advertiser })

  test('is refused with a way out, and the way out works', async ({ page }) => {
    await page.goto('/agency/dashboard')

    await expect(page.getByTestId('agency-portal-denied')).toBeVisible({ timeout: 20000 })
    const actions = await actionsOn(page)
    for (const action of alwaysAvailable) {
      expect(actions, `«${action}» is missing — the refusal is a dead end`).toContain(action)
    }

    // Holding exactly one portal means being told where to go, not handed a switcher of one.
    expect(actions).toContain('recovery-go-to-portal')
    await page.getByTestId('recovery-go-to-portal').click()
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })
  })
})

test.describe('an agency operator who opens the advertiser portal', () => {
  test.use({ storageState: AUTH.owner })

  test('is refused with a way out', async ({ page }) => {
    await page.goto('/app/dashboard')

    await expect(page.getByTestId('app-portal-denied')).toBeVisible({ timeout: 20000 })
    const actions = await actionsOn(page)
    for (const action of alwaysAvailable) {
      expect(actions).toContain(action)
    }
  })

  /**
   * A deep link into a portal they do not hold, then a reload.
   *
   * This is the shape the trap actually took in the wild: a bookmark or a pasted URL, and a refresh
   * that changed nothing. The page must refuse the same way both times — never blank, never a loop.
   */
  test('an old deep link refuses with exits, and still does after a reload', async ({ page }) => {
    await page.goto('/app/dashboard')
    expect(await actionsOn(page)).toEqual(expect.arrayContaining(alwaysAvailable))

    await page.reload()
    expect(await actionsOn(page)).toEqual(expect.arrayContaining(alwaysAvailable))

    // Not a blank page, and not a redirect loop back to a login it already passed.
    await expect(page.locator('body')).not.toHaveText('')
    await expect(page).not.toHaveURL(/\/login/)
  })

})

/**
 * The state the whole feature exists for: signed in, and a member of nothing.
 *
 * `/switch` was the trap's floor — an empty state reached by the one button a refusal offered.
 */
test.describe('an account that belongs to no workspace', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('is offered onboarding and a full set of exits, never a bare empty state', async ({ page }) => {
    await signInAsNoWorkspace(page)
    await page.goto('/switch')

    await expect(page.getByTestId('no-workspace')).toBeVisible({ timeout: 20000 })
    const actions = await actionsOn(page)
    expect(actions, 'somebody with no workspace is not offered a way to get one').toContain('recovery-onboarding')
    for (const action of alwaysAvailable) {
      expect(actions).toContain(action)
    }
    // Nothing to go to and nothing to switch between — offering either would be a lie.
    expect(actions).not.toContain('recovery-go-to-portal')
    expect(actions).not.toContain('recovery-switch')
  })

  /**
   * Coming back later must not land on the wall again.
   *
   * The session is still valid, so this is entirely about what the browser remembers: reaching a dead
   * end clears the stored workspace selection, and the site's front door is public.
   */
  test('returning to the site afterwards lands on the homepage, not the wall', async ({ page }) => {
    await signInAsNoWorkspace(page)
    await page.goto('/switch')
    await expect(page.getByTestId('access-recovery')).toBeVisible({ timeout: 20000 })

    await page.goto('/')
    await expect(page.getByTestId('access-recovery')).toHaveCount(0)
    await expect(page.locator('body')).toContainText(/CampaignsHub/)
  })
})

/**
 * Signing out FROM the refusal must actually end the session and clear the browser.
 *
 * ## Why this describe has its OWN session
 *
 * `AUTH.owner`'s storage state is a cookie pointing at ONE server-side session, shared by every test
 * that uses it. `logout` calls `$request->session()->invalidate()`, so signing out here with the
 * shared cookie destroyed that session for the whole run — and every later test using `AUTH.owner`
 * failed on the login page, a dozen files away from the cause. Signing in here creates a session this
 * test owns and is free to destroy.
 */
test.describe('signing out from a refusal', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('ends the session and clears what this app stored', async ({ page }) => {
    await signInAs(page, 'owner@demo-agency.local')
    await page.goto('/app/dashboard')
    await expect(page.getByTestId('access-recovery')).toBeVisible({ timeout: 20000 })

    await page.evaluate(() => {
      window.localStorage.setItem('campaign-hub-project-storage', JSON.stringify({ state: { currentProjectId: 'stale' }, version: 0 }))
      window.localStorage.setItem('campaign-hub-locale', 'ar')
    })

    await page.getByTestId('recovery-sign-out').click()
    await expect(page).toHaveURL(/\/login/, { timeout: 20000 })
    /*
     * Wait for the new document before reading storage.
     *
     * Sign-out ends with a FULL navigation (see `signOutCompletely` — every provider is rebuilt from
     * nothing, which is what makes the result trustworthy). Reading `localStorage` the instant the URL
     * changes lands mid-navigation and the execution context is torn down underneath the call.
     */
    await page.waitForLoadState('domcontentloaded')
    /*
     * …and wait for something that only the FINAL document has.
     *
     * `toHaveURL` matches as soon as the SPA route changes, which happens BEFORE the hard navigation
     * `signOutCompletely` performs. `waitForLoadState` then resolves against the document that is
     * about to be replaced, and the reload tears the execution context out from under the very next
     * `page.evaluate` — «Execution context was destroyed», seen on firefox.
     *
     * Waiting for the login form is a state assertion, not a pause: the field cannot be visible
     * until the document that owns the storage being read is the one on screen.
     */
    await expect(page.locator('input[type="email"]')).toBeVisible({ timeout: 20000 })

    const state = await page.evaluate(() => ({
      project: window.localStorage.getItem('campaign-hub-project-storage'),
      locale: window.localStorage.getItem('campaign-hub-locale'),
    }))
    expect(state.project, 'the previous person’s project selection survived sign-out').toBeNull()
    // A preference of the PERSON, not of the session — resetting it reads as a bug.
    expect(state.locale, 'the language preference was wiped as a side effect').toBe('ar')

    const status = await page.evaluate(async () => (await fetch('/api/v1/auth/me', { headers: { Accept: 'application/json' } })).status)
    expect(status, 'the session outlived the sign-out').toBe(401)
  })
})
