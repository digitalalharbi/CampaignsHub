import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/** Responsive + RTL/LTR + light/dark + console-cleanliness checks for the campaigns surface. */
/*
 * The ADVERTISER account (LOGIN-002).
 *
 * This file exercises the advertiser portal's campaign surface — its project switcher, its view
 * modes, its mobile rail. It used to sign in as the demo AGENCY and assert that chrome anyway,
 * which only worked while `/app` had no portal guard: an agency operator now lands in `/agency`,
 * where the same engine renders without a project switcher because an agency picks a CLIENT first.
 */
test.use({ storageState: AUTH.advertiser })

const VIEWPORTS = [
  { name: 'mobile-320', width: 320, height: 568 },
  { name: 'mobile-375', width: 375, height: 812 },
  { name: 'desktop', width: 1280, height: 800 },
]

for (const vp of VIEWPORTS) {
  test(`no unintended horizontal scroll @ ${vp.name}`, async ({ page }) => {
    await page.setViewportSize({ width: vp.width, height: vp.height })
    await page.goto('/app/campaigns')
    // The page H1 (not the summary-card H3s, which also contain "الحملات").
    await expect(page.getByRole('heading', { level: 1, name: /Campaigns|الحملات/ })).toBeVisible()
    await page.waitForLoadState('networkidle') // let the list settle before measuring layout

    // Neither the document nor the body may overflow horizontally (1px sub-pixel tolerance).
    const result = await page.evaluate(() => {
      const de = document.documentElement
      const vw = de.clientWidth
      const overflow = Math.max(de.scrollWidth, document.body.scrollWidth) - vw
      const offenders: string[] = []
      if (overflow > 1) {
        document.querySelectorAll('*').forEach((el) => {
          const r = el.getBoundingClientRect()
          if (r.right > vw + 1) {
            offenders.push(`<${el.tagName.toLowerCase()} class="${String((el as HTMLElement).className).slice(0, 60)}"> right=${Math.round(r.right)}`)
          }
        })
      }
      return { overflow, offenders: offenders.slice(0, 6) }
    })
    expect(result.overflow, `overflow=${result.overflow}px; offenders:\n${result.offenders.join('\n')}`).toBeLessThanOrEqual(1)

    /*
     * The project switcher stays reachable at every width. The CLAIM is unchanged; the door moved.
     *
     * It used to be the menu drawer, opened by a hamburger. MOBILE-APP-001 replaced the phone's
     * navigation with a bottom bar and dropped that hamburger below `sm`, which would have left the
     * switcher with no way in at all — so it now sits at the top of the More sheet, above the
     * sections, because choosing the project comes before choosing the section.
     */
    const isMobile = vp.width < 768
    if (isMobile) {
      await page.getByTestId('mobile-more-toggle').click()
      await expect(page.getByTestId('mobile-more-sheet').locator('select')).toBeVisible()
    } else {
      await expect(page.locator('aside select').first()).toBeVisible()
    }
  })
}

test('theme + direction toggle: light/dark and RTL/LTR', async ({ page }) => {
  await page.goto('/app/campaigns')
  const html = page.locator('html')

  const themeBefore = await html.getAttribute('data-theme')
  await page.getByRole('button', { name: /Toggle theme/ }).click()
  await expect(html).not.toHaveAttribute('data-theme', themeBefore ?? '')
  expect(['light', 'dark']).toContain(await html.getAttribute('data-theme'))

  const dirBefore = await html.getAttribute('dir')
  await page.getByRole('button', { name: /Toggle language/ }).click()
  await expect(html).not.toHaveAttribute('dir', dirBefore ?? '')
  expect(['rtl', 'ltr']).toContain(await html.getAttribute('dir'))
})

test('no console errors while using the campaigns surface', async ({ page }) => {
  const errors: string[] = []
  page.on('console', (msg) => {
    if (msg.type() === 'error') errors.push(msg.text())
  })
  page.on('pageerror', (err) => errors.push(String(err)))

  await page.goto('/app/campaigns')
  await expect(page.getByRole('heading', { level: 1, name: /Campaigns|الحملات/ })).toBeVisible()
  // Each campaign row is itself the button (data-testid="campaign-card") — open the first one.
  await page.getByTestId('view-cards').click()
  await page.locator('[data-testid="campaign-card"]').first().click()
  await expect(page).toHaveURL(/\/campaigns\/[^/]+\/[^/]+$/)
  await page.getByRole('tab', { name: /Performance|الأداء/ }).click()

  const unexpected = errors.filter((e) => !/401|Unauthorized|favicon/i.test(e))
  expect(unexpected, `console errors:\n${unexpected.join('\n')}`).toHaveLength(0)
})
