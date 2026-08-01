import { expect, test, type Page } from '@playwright/test'

/**
 * One sign-in engine, five doors (LOGIN-FINAL).
 *
 * The claim is not that five URLs answer 200. It is that each door names the portal it belongs to,
 * that they all run the SAME engine — so an account is refused at a door it does not hold, before a
 * session exists, and told where it does belong — and that the client portal is linked to rather
 * than faked with a password field it does not have.
 */
test.use({ storageState: { cookies: [], origins: [] } })

const DOORS = [
  { path: '/admin/login', ar: 'دخول إدارة المنصة', en: 'Platform administration' },
  { path: '/app/login', ar: 'دخول إدارة الحملات', en: 'Campaign management' },
  { path: '/agency/login', ar: 'دخول الوكالة', en: 'Agency' },
  // `/influencers/login` is deliberately absent: that portal is withdrawn (INFL-OFF-001) and its door
  // now redirects to the services catalogue. It is covered by its own test below.
] as const

/** Each account, and the ONE door it holds. */
const ACCOUNTS = [
  { email: 'owner@demo-company.local', door: '/app/login', lands: /\/app\/dashboard/ },
  { email: 'owner@demo-agency.local', door: '/agency/login', lands: /\/agency/ },
] as const

async function signIn(page: Page, email: string) {
  await page.getByLabel(/البريد الإلكتروني|Email/).fill(email)
  await page.locator('input[type="password"]').fill('password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()
}

/** The withdrawn door still answers — with somewhere to go, not a 404 (INFL-OFF-001). */
test('the withdrawn influencers door leads to the services catalogue', async ({ page }) => {
  await page.goto('/influencers/login')

  await expect(page).toHaveURL(/\/services\?unavailable=influencers/)
  await expect(page.getByTestId('influencers-unavailable')).toBeVisible()
  // Not a placeholder: the catalogue behind the notice is the real one.
  await expect(page.getByTestId('service-categories')).toBeVisible()
})

test('each door names its own portal and offers the others', async ({ page }) => {
  for (const door of DOORS) {
    await page.goto(door.path)
    await expect(page.getByTestId('portal-login-title')).toHaveText(door.ar)
    // The audience line is what lets somebody at the wrong door tell BEFORE typing a password.
    await expect(page.getByTestId('portal-audience')).toBeVisible()

    // Every other door is reachable from here, including the client portal.
    const others = DOORS.filter((d) => d.path !== door.path)
    for (const other of others) {
      const key = other.path.split('/')[1]
      await expect(page.getByTestId(`door-${key}`)).toBeVisible()
    }
    await expect(page.getByTestId('door-portal')).toBeVisible()
  }
})

/**
 * The client portal is NOT a password door, and none of these claims it is.
 *
 * It authenticates by one-time code. A password field for it would be support this product does not
 * have, so it is linked to and the method is stated in words.
 */
test('the client portal is linked, never faked with a password field', async ({ page }) => {
  await page.goto('/app/login')
  await expect(page.getByTestId('client-portal-link')).toHaveAttribute('href', '/portal/login')
  await expect(page.getByText(/لا تحتاج كلمة مرور|no password needed/)).toBeVisible()

  await page.getByTestId('client-portal-link').click()
  await expect(page).toHaveURL(/\/portal\/login$/)
  // Its own page asks for a code, and offers no password anywhere.
  await expect(page.locator('input[type="password"]')).toHaveCount(0)
  await expect(page.getByText(/رمز تحقق|verification code/i).first()).toBeVisible()
})

test('each account signs in at its own door', async ({ page }) => {
  for (const account of ACCOUNTS) {
    await page.context().clearCookies()
    await page.goto(account.door)
    await signIn(page, account.email)
    await expect(page).toHaveURL(account.lands, { timeout: 20000 })
  }
})

/**
 * …and is refused at every door it does not hold — WITHOUT a session being created.
 *
 * The refusal names where the account does belong, and the button there completes the sign-in, so a
 * wrong door is a wrong turn rather than a dead end.
 */
test('a wrong door is refused and then recovered from', async ({ page }) => {
  await page.context().clearCookies()
  await page.goto('/agency/login')
  await signIn(page, 'owner@demo-company.local')

  const notice = page.getByTestId('wrong-portal-notice')
  await expect(notice).toBeVisible()
  await expect(page).toHaveURL(/\/agency\/login$/)

  await notice.getByRole('button').click()
  await expect(page).toHaveURL(/\/app\/dashboard/, { timeout: 20000 })
})

/** Wrong password says so, in the interface's language, and never mentions the network. */
test('a wrong password is named as a wrong password', async ({ page }) => {
  await page.context().clearCookies()
  await page.goto('/app/login')
  await page.getByLabel(/البريد الإلكتروني|Email/).fill('owner@demo-company.local')
  await page.locator('input[type="password"]').fill('not-the-password')
  await page.getByRole('button', { name: /تسجيل الدخول|Sign in/ }).click()

  const error = page.getByText(/بيانات الدخول غير صحيحة|do not match our records/)
  await expect(error).toBeVisible()
  await expect(page.getByText(/الاتصال بالخادم|network|connection/i)).toHaveCount(0)
})

test('the doors work on a phone, in both directions', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 })

  for (const door of DOORS) {
    await page.goto(door.path)
    await expect(page.getByTestId('portal-login-title')).toBeVisible()

    // The form must be usable, and the page must not scroll sideways.
    await expect(page.locator('input[type="password"]')).toBeVisible()
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)
    expect(overflow, `${door.path} scrolls sideways on a phone`).toBe(false)
  }

  // …and in English, left-to-right.
  await page.goto('/app/login')
  await page.getByRole('button', { name: 'Toggle language' }).click()
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr')
  await expect(page.getByTestId('portal-login-title')).toHaveText('Campaign management')
})

/**
 * Every door offers Google and Apple, and neither pretends to work (ADMIN-100).
 *
 * The block existed only on the legacy `/login`, so the four per-portal doors — the ones the product
 * actually sends people to — had no social sign-in at all. It is shown on all of them now, and a
 * provider with no credentials is DISABLED and says why: an enabled button with nothing behind it
 * sends somebody to an error page they cannot act on, and claims support the platform does not have.
 */
test('each door offers social sign-in, disabled and explained while credentials are missing', async ({ page }) => {
  for (const door of DOORS) {
    await page.goto(door.path)

    const social = page.getByTestId('social-signin')
    await expect(social, `${door.path} offers no social sign-in`).toBeVisible()
    await expect(page.getByTestId('oauth-google')).toBeVisible()
    await expect(page.getByTestId('oauth-apple')).toBeVisible()

    // No credentials are configured in any environment yet, so both must be refused up front and
    // the reason stated — never a button that looks live.
    await expect(page.getByTestId('oauth-google')).toBeDisabled()
    await expect(page.getByTestId('oauth-apple')).toBeDisabled()
    await expect(page.getByTestId('oauth-awaiting')).toBeVisible()
  }
})

/** The platform console is never opened by a public form, so it offers no way to create one. */
test('the platform console door offers no sign-up', async ({ page }) => {
  await page.goto('/admin/login')
  await expect(page.locator('a[href="/register"]')).toHaveCount(0)

  // …while the doors to a product somebody can buy do offer one.
  await page.goto('/app/login')
  await expect(page.locator('a[href="/register"]')).toHaveCount(1)
})
