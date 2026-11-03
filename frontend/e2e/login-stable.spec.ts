import { expect, test, type Page } from '@playwright/test'

/**
 * LOGIN-NO-EMAIL-CODE-001 (#943) — the sign-in page holds still from its first frame.
 *
 * The geometry is measured, not eyeballed: every landmark's box while the providers lookup is still open, against the same box after it
 * has answered, at both widths and in both directions. The lookup is held open by the test, so the first reading is the real loading
 * state and not a race.
 */
test.use({ storageState: { cookies: [], origins: [] } })

const LANDMARKS = ['login-email', 'login-password', 'login-methods', 'login-help-open']

const boxes = (page: Page) => page.evaluate((ids) => Object.fromEntries(ids.map((id) => {
  const box = document.querySelector(`[data-testid="${id}"]`)?.getBoundingClientRect()

  return [id, box ? [Math.round(box.x), Math.round(box.y), Math.round(box.width), Math.round(box.height)] : null]
})), LANDMARKS)

for (const [width, height] of [[390, 844], [1366, 768]]) {
  for (const locale of ['ar', 'en']) {
    for (const outcome of ['answers', 'fails', 'says Google is unavailable'] as const) {
      test(`${width}px ${locale}: nothing moves when the lookup ${outcome}`, async ({ page }) => {
        await page.setViewportSize({ width, height })
        await page.addInitScript((l) => localStorage.setItem('campaign-hub-locale', l), locale)

        let release = () => {}
        const held = new Promise<void>((resolve) => { release = resolve })
        await page.route('**/auth/oauth/providers', async (route) => {
          await held
          if (outcome === 'fails') return route.fulfill({ status: 500, json: { message: 'down' } })
          const available = outcome === 'answers'

          return route.fulfill({ json: { data: { providers: [{ provider: 'google', label: { en: 'Google', ar: 'Google' }, status: available ? 'live' : 'awaiting_credentials', available }] } } })
        })

        await page.goto('/login')
        await expect(page.getByTestId('login-google-reserved')).toBeVisible()
        await expect(page.getByTestId('login-request-code')).toHaveCount(0)
        const before = await boxes(page)
        expect(before['login-methods'], 'Google’s row is not reserved').not.toBeNull()

        release()
        await expect(page.getByTestId('login-google-reserved')).toHaveCount(0, { timeout: 45_000 })
        await expect(page.getByTestId(outcome === 'says Google is unavailable' ? 'login-home' : 'login-google')).toBeVisible()
        expect(await boxes(page)).toEqual(before)
        await expect(page.getByTestId('login-request-code')).toHaveCount(0)
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
      })
    }
  }
}

test('no email-code request leaves the page, and the password route still works', async ({ page }) => {
  const asked: string[] = []
  page.on('request', (r) => { if (/\/auth\/(email-code|methods|method)\b/.test(r.url())) asked.push(r.url()) })

  await page.goto('/login')
  await page.getByTestId('login-email').fill('advertiser@campaignshub.io')
  await page.locator('input[type="password"]').fill('password')
  await page.locator('[data-testid="login-identify"] button[type="submit"]').click()

  await expect(page).toHaveURL(/\/app\//, { timeout: 20_000 })
  expect(asked).toEqual([])
})
