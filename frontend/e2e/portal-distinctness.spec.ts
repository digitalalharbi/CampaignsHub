import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders, signIn } from './helpers'

/**
 * The five portals must be different products in the browser, not only in the API (REG-001).
 *
 * The regression this guards against was visible exactly here and nowhere else: every account
 * signed in and met the same multi-client agency console. The nav was derived from the workspace's
 * ACCOUNT TYPE through a `personal` / `company` fork whose permissive branch was the agency menu —
 * and whose fallback, for any workspace that never answered the question, was that same branch. So
 * a freelancer, an in-house team, and every self-registered account landed in `/app` and were handed
 * Clients, a requests inbox and agency invoicing.
 *
 * A unit test on the section lists cannot catch that, because the lists were never the thing that
 * was wrong — the wiring was. These tests read the rendered rail.
 */

/** A freelancer: the account type that used to be handed the agency console. */
const FREELANCER = { email: `e2e-freelancer-${Date.now()}@probe.test`, password: 'Password123!' }

test.describe('the portals are different products', () => {
  /**
   * Register and take the account all the way through the REAL gated path, because a half-onboarded
   * account never reaches a portal at all — it sits on its registration status page, which would
   * make every assertion below pass or fail for the wrong reason.
   */
  test.beforeAll(async ({ request }) => {
    /*
     * Re-prime CSRF before EVERY unsafe call.
     *
     * Registering and verifying each sign the user in, and a session regeneration takes the old
     * XSRF token with it — so a token captured once and reused returns 419 from the next POST
     * onwards. Cheap enough to do every time, and it removes a whole class of confusing failure.
     */
    const post = async (path: string, data?: unknown) =>
      request.post(path, { headers: await csrfHeaders(request), data: data ?? {} })

    const registered = await post('/api/v1/auth/register', {
      name: 'E2E Freelancer',
      email: FREELANCER.email,
      password: FREELANCER.password,
      password_confirmation: FREELANCER.password,
      tenant_name: `E2E Freelance Studio ${Date.now()}`,
      account_type: 'freelancer',
      service: 'paid_media',
    })
    // 202, not 201: an application was received. Nothing has been created that anyone can sign in
    // with, which is the point of SIGNUP-002 and the reason this beforeAll has a second step.
    expect(registered.status(), 'the application must be accepted').toBe(202)

    // No mail provider is configured, so the token comes back on the response in non-production —
    // an honest dev link rather than a pretend "email sent".
    const devLink = (await registered.json()).data.verification.dev_link as string
    const verified = await post('/api/v1/auth/registration/verify-email', { token: devLink.split('token=')[1] })
    expect(verified.status(), 'email verification must succeed').toBe(200)

    const type = await post('/api/v1/onboarding/account-type', { account_type: 'freelancer' })
    expect(type.status(), 'account-type step must succeed').toBe(200)
    await post('/api/v1/onboarding/service', { service: 'paid_media' })
    await post('/api/v1/onboarding/workspace', { name: 'E2E Freelance Studio' })
    // A freelancer is an advertiser, so onboarding asks for a first PROJECT and never for a client.
    const project = await post('/api/v1/onboarding/first-project', { name: 'First Project' })
    expect(project.status(), 'an advertiser is asked for a project, not a client').toBe(200)
    await post('/api/v1/onboarding/complete')
  })

  test('an advertiser sees their own campaigns and no agency tooling', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, FREELANCER.email, FREELANCER.password)

    // The server decides the destination. A freelancer is an advertiser.
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })

    const rail = page.locator('aside').first()
    await expect(rail).toBeVisible({ timeout: 20000 })

    // The advertiser's own work is here…
    for (const section of [/الحملات|Campaigns/, /المشاريع|Projects/, /التحليلات|Analytics/]) {
      await expect(rail.getByText(section).first()).toBeVisible()
    }

    // …and the agency's is not. These four are the regression, named one by one.
    for (const absent of [/^العملاء$|^Clients$/, /^الطلبات$|^Requests$/,
      /^المحادثات$|^Conversations$/, /^الفواتير$|^Billing$/]) {
      await expect(rail.getByText(absent)).toHaveCount(0)
    }
  })

  test('an agency sees its clients, and its rail is not the advertiser rail', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, 'owner@demo-agency.local', 'password')

    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })
    await expect(page.getByTestId('agency-shell')).toBeVisible({ timeout: 20000 })

    const rail = page.locator('aside').first()
    await expect(rail.getByText(/^العملاء$|^Clients$/).first()).toBeVisible()
    /*
     * «الفريق والصلاحيات / Team & permissions» — renamed from «…والنطاقات / …& scopes» by SIMPLIFY-002.
     *
     * A «scope» is what the code calls the restriction; a permission is what the person granting it
     * thinks they are granting. The page, the route and the mechanism are unchanged — and the point
     * this assertion makes is unchanged with them: the advertiser rail has no such entry at all.
     */
    await expect(rail.getByText(/الفريق والصلاحيات|Team & permissions/).first()).toBeVisible()
  })

  /**
   * Typing another portal's URL is not a way in. The agency portal's gate says so plainly rather
   * than rendering a screen of failed requests, which is what an unguarded route would have done.
   */
  test('typing the agency URL as an advertiser is refused honestly', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, FREELANCER.email, FREELANCER.password)
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })

    await page.goto('/agency/clients')

    await expect(page.getByTestId('agency-portal-denied')).toBeVisible({ timeout: 20000 })

    /*
     * Refused, and given a way out — ACCESS-EXIT-001.
     *
     * This asserted on ONE button, «انتقل إلى مساحاتك», which for somebody holding no membership
     * pointed at a screen that offered nothing at all. The refusal now renders the shared recovery
     * block, so the assertion is on the ACTIONS being there rather than on a particular label: a
     * refusal whose copy is rewritten should keep passing, one that loses its exits must not.
     */
    await expect(page.getByTestId('access-recovery')).toBeVisible()
    await expect(page.getByTestId('recovery-go-to-portal')).toBeVisible()
    await expect(page.getByTestId('recovery-sign-out')).toBeVisible()
  })

  /** The API refuses the same thing, so the gate above is a courtesy and not the boundary. */
  test('the agency API refuses an advertiser session', async ({ request }) => {
    await request.post('/api/v1/auth/login', {
      headers: await csrfHeaders(request),
      data: { email: FREELANCER.email, password: FREELANCER.password },
    })

    for (const endpoint of ['/api/v1/app/clients', '/api/v1/app/requests',
      '/api/v1/client-workspaces', '/api/v1/agency/dashboard']) {
      const res = await request.get(endpoint, { headers: API_HEADERS })
      expect(res.status(), `${endpoint} must be refused an advertiser`).toBe(403)
    }
  })

  /**
   * An old `/app/clients` bookmark still resolves — moved is not deleted. It lands in the agency
   * portal, which then answers for itself: through to the page for a member, an honest refusal
   * otherwise.
   */
  test('a pre-move /app link redirects into the agency portal', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, 'owner@demo-agency.local', 'password')
    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })

    await page.goto('/app/clients')
    await expect(page).toHaveURL(/\/agency\/clients/, { timeout: 20000 })
  })

  /** Reload, direct link and back must all land in the same portal — no drift between them. */
  test('the portal survives reload, a direct link and going back', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, FREELANCER.email, FREELANCER.password)
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })

    await page.reload()
    await expect(page).toHaveURL(/\/app\//)

    await page.goto('/app/campaigns')
    await expect(page).toHaveURL(/\/app\/campaigns/)

    await page.goBack()
    await expect(page).toHaveURL(/\/app\//)
  })
})

/** The platform owner's console is its own portal, entered by a flag and never by a membership. */
test.describe('the owner console stands apart', () => {
  test.use({ storageState: AUTH.owner })

  test('an agency operator is refused /admin', async ({ page }) => {
    await page.goto('/admin')

    // Not the admin console — either turned away or sent back to their own portal.
    await expect(page.getByText(/لوحة المنصة|Platform overview/)).toHaveCount(0)
  })
})

/**
 * The sign-in page is ONE door now (LOGIN-UNIFIED-001).
 *
 * The four tests that used to live here drove the portal chooser: each tab's demo identity, the
 * client tab's link, the refusal when you picked a portal you do not hold, and the proof that
 * picking one granted nothing. All four tested a question the page no longer asks — the visitor
 * never names a portal, so there is no wrong choice left to refuse.
 *
 * What survives is the property those tests were protecting, restated against the shape that
 * replaced them: an account reaches only the portal its memberships allow, whichever address it
 * signed in from. The rest lives in `login-unified.spec.ts`.
 */
test.describe('signing in', () => {
  /**
   * Signing in from an address that names a portal grants nothing.
   *
   * `/admin/login` is the strongest version of this: it used to be the platform console's own door.
   * An advertiser signing in there must still land in `/app`, and must still be refused at
   * `/agency/clients` afterwards.
   */
  test('an address naming a portal grants no access to it', async ({ page }) => {
    await page.goto('/admin/login')
    await signIn(page, 'owner@demo-company.local')

    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })
    await expect(page.getByText(/لوحة المنصة|Platform overview/)).toHaveCount(0)

    await page.goto('/agency/clients')
    await expect(page.getByTestId('agency-portal-denied')).toBeVisible({ timeout: 20000 })
  })


  /** Google and Apple are shown, and shown honestly, without credentials configured. */
  test('social sign-in is offered but reports its real state', async ({ page }) => {
    await page.goto('/login')

    await expect(page.getByTestId('oauth-google')).toBeVisible()
    await expect(page.getByTestId('oauth-apple')).toBeVisible()
    // Not configured in this environment, so inert and saying why — never a button that fails.
    await expect(page.getByTestId('oauth-google')).toBeDisabled()
    await expect(page.getByTestId('oauth-apple')).toBeDisabled()
    await expect(page.getByTestId('oauth-awaiting')).toContainText(/بانتظار|awaiting/i)
  })

  /** And the advertiser portal now refuses an agency operator the same way every other one does. */
  test('the advertiser portal refuses an agency operator', async ({ page }) => {
    await page.goto('/login')
    await signIn(page, 'owner@demo-agency.local', 'password')
    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })

    await page.goto('/app/dashboard')
    await expect(page.getByTestId('app-portal-denied')).toBeVisible({ timeout: 20000 })
  })
})
