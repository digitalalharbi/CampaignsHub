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
async function signInAsNoWorkspace(page: Page): Promise<void> {
  await page.goto('/login')
  const status = await page.evaluate(async () => {
    const xsrf = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '')
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    const res = await fetch('/api/v1/auth/login', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
      body: JSON.stringify({ email: 'no-workspace@demo.local', password: 'password' }),
    })
    return res.status
  })
  expect(status, 'the seeded no-workspace account could not sign in').toBe(200)
}

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

  /**
   * Signing out FROM the refusal must actually end the session and clear the browser.
   *
   * The half-measure this replaces cleared the server session and left the persisted project
   * selection behind, so the next person to sign in on that machine inherited it.
   */
  test('signing out from the refusal ends the session and clears what this app stored', async ({ page }) => {
    await page.goto('/app/dashboard')
    await expect(page.getByTestId('access-recovery')).toBeVisible({ timeout: 20000 })

    await page.evaluate(() => {
      window.localStorage.setItem('campaign-hub-project-storage', JSON.stringify({ state: { currentProjectId: 'stale' }, version: 0 }))
      window.localStorage.setItem('campaign-hub-locale', 'ar')
    })

    await page.getByTestId('recovery-sign-out').click()
    await expect(page).toHaveURL(/\/login/, { timeout: 20000 })

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
