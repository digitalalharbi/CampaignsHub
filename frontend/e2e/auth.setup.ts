import { expect, test as setup } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * Logs in each demo role ONCE and saves its authenticated storage state, so the specs reuse the
 * session instead of logging in per-test (which trips the backend login throttle). Runs as a
 * dependency before the test project.
 */
const ROLES = [
  { email: 'agency@campaignshub.io', file: AUTH.owner },
  { email: 'analyst@demo-agency.local', file: AUTH.analyst },
  { email: 'viewer@demo-agency.local', file: AUTH.viewer },
  // A real advertiser, for the advertiser portal's own surfaces.
  { email: 'advertiser@campaignshub.io', file: AUTH.advertiser },
  // The platform owner. Without it, `/admin` had no signed-in session to audit with. The influencer
  // operator is deliberately absent: that portal is withdrawn (INFL-OFF-001), so its demo login is
  // no longer seeded and signing in as it would authenticate nothing.
  { email: 'admin@campaignshub.io', file: AUTH.admin },
  // The client portal's own customer (REVIEW-001c). Until the membership engine could OPEN the
  // portal — rather than only narrow a session the OTP cookie had already opened — this account
  // signed in, was told its portal was `/portal`, and met 401 on every page there.
  { email: 'client@campaignshub.io', file: AUTH.client },
]

for (const role of ROLES) {
  setup(`authenticate ${role.email}`, async ({ page }) => {
    /*
     * Two steps now, not one (LOGIN-UNIFIED-001).
     *
     * `/login` asks who you are, the SERVER says whether that account uses a password or a one-time
     * code, and only then does the password field exist. Filling `input[type="password"]` straight
     * after `goto` matched nothing and failed as a timeout — which reads like a slow boot rather
     * than like a form that has changed shape.
     */
    await page.goto('/login')
    await page.getByTestId('login-email').fill(role.email)

    await expect(
      page.getByTestId('login-password'),
      'the sign-in card never rendered its password field',
    ).toBeVisible({ timeout: 20_000 })
    await page.getByTestId('login-password').locator('input[type="password"]').fill('password')

    /*
     * Wait on the login RESPONSE, then on the navigation — two separate facts.
     *
     * This used to be a bare `expect(page).not.toHaveURL(/\/login$/)` on the default 5s window, which
     * could not tell «the server refused these credentials» from «the SPA has not finished booting
     * yet». On a dev server Playwright has just started, the second is real: Vite answers the health
     * URL as soon as it binds a port, long before it has transformed the module graph, so the first
     * page load of a run is the slowest one anybody will ever see. Every so often a whole run died in
     * setup with six identical timeouts and nothing to say which of the two had happened.
     *
     * Asserting the response first makes the refusal case fail LOUDLY and immediately — a 401 or 422
     * here is a real defect and now reads as one — and leaves the navigation assertion measuring only
     * what it is for. Nothing has been weakened: the credentials still have to be accepted and the
     * browser still has to leave `/login`.
     */
    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/v1/auth/login') && r.request().method() === 'POST'),
      page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click(),
    ])
    // The body travels with the failure. A bare status tells you it was refused; the body tells you
    // WHY, which is the difference between a five-minute diagnosis and an hour of guessing.
    const body = await response.text().catch(() => '<unreadable>')
    expect(response.status(), `login refused for ${role.email}: ${body.slice(0, 400)}`).toBe(200)

    await expect(page).not.toHaveURL(/\/login$/, { timeout: 30_000 })
    await page.context().storageState({ path: role.file })
  })
}
