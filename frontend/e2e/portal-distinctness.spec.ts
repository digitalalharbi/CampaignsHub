import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, csrfHeaders } from './helpers'

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
    await page.locator('input[type="email"]').fill(FREELANCER.email)
    await page.locator('input[type="password"]').fill(FREELANCER.password)
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()

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
    await page.locator('input[type="email"]').fill('owner@demo-agency.local')
    await page.locator('input[type="password"]').fill('password')
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()

    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })
    await expect(page.getByTestId('agency-shell')).toBeVisible({ timeout: 20000 })

    const rail = page.locator('aside').first()
    await expect(rail.getByText(/^العملاء$|^Clients$/).first()).toBeVisible()
    await expect(rail.getByText(/الفريق والنطاقات|Team & scopes/).first()).toBeVisible()
  })

  /**
   * Typing another portal's URL is not a way in. The agency portal's gate says so plainly rather
   * than rendering a screen of failed requests, which is what an unguarded route would have done.
   */
  test('typing the agency URL as an advertiser is refused honestly', async ({ page }) => {
    await page.goto('/login')
    await page.locator('input[type="email"]').fill(FREELANCER.email)
    await page.locator('input[type="password"]').fill(FREELANCER.password)
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })

    await page.goto('/agency/clients')

    await expect(page.getByTestId('agency-portal-denied')).toBeVisible({ timeout: 20000 })
    // Refused, and told where they CAN go — not a dead end.
    await expect(page.getByRole('button', { name: /انتقل إلى مساحاتك|Go to your workspaces/ })).toBeVisible()
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
    await page.locator('input[type="email"]').fill('owner@demo-agency.local')
    await page.locator('input[type="password"]').fill('password')
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })

    await page.goto('/app/clients')
    await expect(page).toHaveURL(/\/agency\/clients/, { timeout: 20000 })
  })

  /** Reload, direct link and back must all land in the same portal — no drift between them. */
  test('the portal survives reload, a direct link and going back', async ({ page }) => {
    await page.goto('/login')
    await page.locator('input[type="email"]').fill(FREELANCER.email)
    await page.locator('input[type="password"]').fill(FREELANCER.password)
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
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
 * The sign-in page is one engine with per-portal context (LOGIN-001).
 *
 * Every tab used to offer `owner@demo-agency.local`, so whichever portal a tester picked they signed
 * in as the same agency operator and landed in the agency portal — which reads as the tabs being
 * decorative. Each tab now names its own demo identity, and choosing a portal you do not hold is
 * answered in words rather than by quietly routing you somewhere else.
 */
test.describe('signing in through a portal', () => {
  test('each portal tab offers its own demo identity', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByTestId('demo-credentials')).toContainText('owner@demo-company.local')

    await page.getByTestId('login-portal-agency').click()
    await expect(page.getByTestId('demo-credentials')).toContainText('owner@demo-agency.local')

    await page.getByTestId('login-portal-influencer').click()
    await expect(page.getByTestId('demo-credentials')).toContainText('layla@creators.demo')

    // …and each one says what kind of account it is, so a tester knows what they should then see.
    await expect(page.getByTestId('demo-account-kind')).toBeVisible()
  })

  /** The client portal is a different door — one-time code, no password — so its tab leads there. */
  test('the client tab leads to the portal login, not this form', async ({ page }) => {
    await page.goto('/login')
    await page.getByTestId('login-portal-client').click()

    await expect(page).toHaveURL(/\/portal\/login/)
  })

  /**
   * Choosing a portal you do not hold is EXPLAINED, not routed around.
   *
   * The agency owner signing in through the advertiser tab is a real person making a real mistake.
   * Sending them to `/agency` silently is a correct destination and a confusing one: their choice
   * was overridden with no indication it had been.
   */
  test('signing in through a portal you do not hold is refused in the form', async ({ page }) => {
    await page.goto('/login?portal=agency')
    await page.locator('input[type="email"]').fill('owner@demo-company.local')
    await page.locator('input[type="password"]').fill('password')
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()

    const notice = page.getByTestId('wrong-portal-notice')
    await expect(notice).toBeVisible({ timeout: 20000 })
    await expect(notice).toContainText(/غير مخول|not authorised/)
    // Told, and still on the login page — not dropped into a dashboard that is not theirs.
    await expect(page).toHaveURL(/\/login/)

    // NO SESSION was created: this was a refused sign-in, not a redirect with a message.
    const me = await page.request.get('/api/v1/auth/me', { headers: { Accept: 'application/json' } })
    expect(me.status(), 'a refused sign-in must not leave a session behind').toBe(401)

    // …and the way out works: it signs them in properly and lands them in their own portal.
    await notice.getByRole('button').click()
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })
  })

  /** The tab is context, never a grant: picking "Agency" does not open the agency portal. */
  test('choosing a portal tab grants nothing', async ({ page }) => {
    await page.goto('/login?portal=agency')
    await page.locator('input[type="email"]').fill('owner@demo-company.local')
    await page.locator('input[type="password"]').fill('password')
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()

    const notice = page.getByTestId('wrong-portal-notice')
    await expect(notice).toBeVisible({ timeout: 20000 })
    // Sign in properly, THEN try the agency portal by URL — it is still refused.
    await notice.getByRole('button').click()
    await expect(page).toHaveURL(/\/app\//, { timeout: 20000 })

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
    await page.locator('input[type="email"]').fill('owner@demo-agency.local')
    await page.locator('input[type="password"]').fill('password')
    await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
    await expect(page).toHaveURL(/\/agency/, { timeout: 20000 })

    await page.goto('/app/dashboard')
    await expect(page.getByTestId('app-portal-denied')).toBeVisible({ timeout: 20000 })
  })
})
