import { test } from '@playwright/test'
import { signIn } from './helpers'

/**
 * Not a gate — a camera.
 *
 * Presentation requirements are judged by what a reader sees, and a passing assertion is not that.
 * This file signs in and photographs the product at a desktop width and a phone width, in Arabic and
 * in English, so the evidence attached to a Matrix row is the screen itself rather than a claim
 * about it. It asserts nothing beyond the page having rendered, so it can never fail the gate over a
 * design opinion.
 *
 * Excluded from `npm run gate` by its `@evidence` tag — run it deliberately:
 *
 *   npx playwright test evidence.spec.ts --project=chromium
 *
 * The locale is written into `localStorage` before the app boots rather than clicked in the header:
 * the toggle is one more thing that can move, and a screenshot run that silently photographed the
 * wrong language would be worse than no screenshot.
 */
const DESKTOP = { width: 1440, height: 900 }
const PHONE = { width: 390, height: 844 }

/** [file, account, path] — `/app/*` belongs to the advertiser portal, `/agency/*` to the agency one. */
const SURFACES: [string, string, string][] = [
  ['dashboard', 'advertiser@campaignshub.io', '/app'],
  ['analytics', 'advertiser@campaignshub.io', '/app/analytics'],
  ['campaigns', 'advertiser@campaignshub.io', '/app/campaigns'],
  ['content', 'advertiser@campaignshub.io', '/app/content'],
  ['spend-limits', 'advertiser@campaignshub.io', '/app/spend-limits'],
  ['reports', 'advertiser@campaignshub.io', '/app/reports'],
  ['agency', 'agency@campaignshub.io', '/agency'],
  ['integrations', 'agency@campaignshub.io', '/agency/integrations'],
]

for (const [name, account, path] of SURFACES) {
  for (const [size, viewport] of [['desktop', DESKTOP], ['phone', PHONE]] as const) {
    for (const locale of ['ar', 'en'] as const) {
      test(`@evidence ${name} ${size} ${locale}`, async ({ page }) => {
        await page.setViewportSize(viewport)
        await page.addInitScript((value) => localStorage.setItem('campaign-hub-locale', value), locale)
        await signIn(page, account, 'password')
        // signIn only clicks submit; going straight on races the POST and lands back at /login.
        await page.waitForURL((url) => !/\/login/.test(url.pathname), { timeout: 30_000 })
        await page.goto(path)
        await page.waitForLoadState('networkidle').catch(() => undefined)
        await page.waitForTimeout(1500)
        await page.screenshot({
          path: new URL(`../evidence/${name}-${size}-${locale}.png`, import.meta.url).pathname,
          fullPage: false,
        })
      })
    }
  }
}
