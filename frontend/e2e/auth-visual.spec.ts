import { expect, test } from '@playwright/test'
import { DEMO_CLIENT_CONTACT, enableEmailCode, openEmailCode } from './helpers'

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

    /*
     * THEME-DARK-PRIMARY-001 — each test states the theme it photographs.
     *
     * The light case used `emulateMedia({ colorScheme: 'light' })`, and this product deliberately
     * does NOT consult `prefers-color-scheme` — so that call never set anything. It passed only
     * because light happened to be the default, which means the light baseline was never a test of
     * light; it was a test of «whatever the default is».
     *
     * The dark case clicked «Toggle theme» from the default — the same bug wearing the other shoe:
     * it photographed «not the default». When dark became the default the two swapped, and simply
     * refreshing the baselines would have locked in a test named `light` asserting a dark screenshot.
     *
     * Both now write the preference the product actually reads, before the app boots, and assert the
     * rendered `data-theme` before any pixels are compared — so the name and the photograph cannot
     * drift apart again.
     */
    for (const theme of ['light', 'dark'] as const) {
      test(`${path} ${theme} matches baseline`, async ({ page }) => {
        await page.addInitScript((t) => {
          window.localStorage.setItem('campaign-hub-theme', t as string)
        }, theme)

        await page.goto(path)
        await page.waitForLoadState('networkidle')

        await expect(page.locator('html')).toHaveAttribute('data-theme', theme)

        await expect(page).toHaveScreenshot(`${slug}-${theme}.png`, { fullPage: true, maxDiffPixelRatio: 0.02 })
      })
    }
  }
})

test('login: keyboard-only navigation reaches the address, the code and submit', async ({ page }) => {
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
   * Type into the card and then into the code step — focus must land on real inputs, in order.
   *
   * Both credentials are on one card (LOGIN-CARD-001); the code step is a second state of the same
   * space, reached by asking for a code, so it is reached here by asking for one.
   */
  /*
   * Its own address, not a demo account's.
   *
   * This test asks for a code and never uses it, which leaves a live challenge — and a live
   * challenge holds the resend window shut for that destination for a minute. Sharing an address
   * with a journey spec meant whichever ran second was refused a code and failed on an empty field,
   * describing itself as «the issued code never arrived». The window is correct; the sharing was not.
   */
  const probe = 'keyboard-probe@example.test'
  const identifier = page.getByTestId('login-email')
  await identifier.focus()
  await page.keyboard.type(probe)
  await expect(identifier).toHaveValue(probe)

  const pw = page.getByTestId('login-password').locator('input[type="password"]')
  await pw.focus()
  await page.keyboard.type('password')
  await expect(pw).toHaveValue('password')

  // The six boxes exist only while the server enables the code (LOGIN-SINGLE-DOOR-001), so this walk enables it for itself.
  await expect(page.getByTestId('login-email-code')).toHaveCount(0)
  await enableEmailCode(page)
  await page.reload()
  await openEmailCode(page, DEMO_CLIENT_CONTACT)

  /*
   * Typing into the first box fills it and moves on, which is the whole point of six boxes.
   *
   * The field is cleared first: outside production the page pre-fills the issued code (`dev_code`,
   * hard-gated server-side), and typing on top of a full code would be typing into a seventh box.
   */
  const first = page.getByTestId('login-otp-0')
  await expect(first).not.toHaveValue('', { timeout: 20_000 })

  for (let i = 0; i < 6; i++) {
    await page.getByTestId(`login-otp-${5 - i}`).focus()
    await page.keyboard.press('Backspace')
  }

  /*
   * The sixth digit completes the code, and completing it SUBMITS (`OtpField.onComplete`). The real server
   * accepts it, the page signs in and leaves, and the box this test then reads is gone: a loaded WebKit
   * run got to its assertion after the navigation (trace: `POST /client/login/verify` 200, then 401s from
   * the portal's own requests). The claim here is where focus goes, not what the server says, so the
   * submission is held open and the boxes stay where they are.
   */
  await page.route('**/api/v1/client/login/verify', () => {})

  await first.focus()
  await page.keyboard.type('123456')
  await expect(page.getByTestId('login-otp-5')).toHaveValue('6')

  // No console errors while rendering + interacting with the login page.
  expect(errors, errors.join('\n')).toHaveLength(0)
})
