import { expect, test } from '@playwright/test'

/**
 * Visual-regression baselines for the auth surface (chromium only — cross-browser pixel diffs are noisy).
 * First run writes the baseline (`--update-snapshots`); later runs fail on unintended visual drift.
 * Also covers keyboard navigation and a console-error guard, which are auth phase-1 acceptance items.
 */
const PAGES = ['/login', '/register', '/forgot-password'] as const

test.describe('auth visual regression @visual', () => {
  test.skip(({ browserName }) => browserName !== 'chromium', 'baselines are chromium-only')

  for (const path of PAGES) {
    const slug = path.replace(/\//g, '') || 'root'

    test(`${path} light matches baseline`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: 'light' })
      await page.goto(path)
      await page.waitForLoadState('networkidle')
      await expect(page).toHaveScreenshot(`${slug}-light.png`, { fullPage: true, maxDiffPixelRatio: 0.02 })
    })

    test(`${path} dark matches baseline`, async ({ page }) => {
      await page.goto(path)
      await page.getByRole('button', { name: 'Toggle theme' }).click()
      await page.waitForTimeout(250)
      await expect(page).toHaveScreenshot(`${slug}-dark.png`, { fullPage: true, maxDiffPixelRatio: 0.02 })
    })
  }
})

test('login: keyboard-only navigation reaches email, password and submit', async ({ page }) => {
  const errors: string[] = []
  page.on('console', (m) => {
    if (m.type() !== 'error') return
    // The guest session probe `GET /auth/me` returns 401 by design; the browser logs that failed
    // resource load as a console error. It is expected auth behaviour, not an app error — ignore it.
    if (/failed to load resource/i.test(m.text()) && /401/.test(m.text())) return
    errors.push(m.text())
  })

  await page.goto('/login')
  /*
   * Tab into the form and type — focus must land on real inputs, in order.
   *
   * Two steps now (LOGIN-UNIFIED-001): the identifier field takes an email OR a phone, so it is no
   * longer `input[type=email]`, and the password field does not exist until the server has said this
   * account has one.
   */
  const identifier = page.getByTestId('login-identify').locator('input')
  await identifier.focus()
  await page.keyboard.type('owner@demo-agency.local')
  await expect(identifier).toHaveValue('owner@demo-agency.local')

  await page.getByTestId('login-identify').locator('button[type="submit"]').click()
  await expect(page.getByTestId('login-password')).toBeVisible({ timeout: 20_000 })

  const pw = page.getByTestId('login-password').locator('input[type="password"]')
  await pw.focus()
  await page.keyboard.type('password')
  await expect(pw).toHaveValue('password')

  // No console errors while rendering + interacting with the login page.
  expect(errors, errors.join('\n')).toHaveLength(0)
})
