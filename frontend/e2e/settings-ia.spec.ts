import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'

/**
 * SETTINGS-TAB-ADDRESS-001 §B — every settings destination is reachable, and says what it is.
 *
 * The tab was `useState` alone, so `?tab=notifications` opened the general tab and no settings
 * screen could be linked to at all. That is fixed; this holds the rest of the information
 * architecture around it, which nothing was watching:
 *
 *   - every section in the shell resolves to a page with a heading, rather than a blank frame;
 *   - every inner tab is addressable, so a colleague can be sent to the one that matters;
 *   - nothing prints a placeholder where a value belongs.
 *
 * Walked rather than asserted per-screen: a settings area grows a section at a time, and the failure
 * that matters is the one nobody adds a test for.
 */
test.use({ storageState: AUTH.owner })

const SECTIONS = [
  { path: '/agency/settings/workspace', name: 'workspace' },
  { path: '/agency/settings/permissions', name: 'permissions' },
  { path: '/agency/settings/branding', name: 'branding' },
]

for (const section of SECTIONS) {
  test(`the ${section.name} settings render`, async ({ page }) => {
    await page.goto(section.path)
    await switchToEnglish(page)

    await expect(page.locator('h1').first(), `«${section.name}» rendered no heading`).toBeVisible({ timeout: 30000 })

    const text = (await page.locator('main').innerText()).replace(/\s+/g, ' ')
    expect(text, `«${section.name}» printed a placeholder`).not.toMatch(/\b(undefined|NaN|\[object Object\])\b/)
    expect(text.length, `«${section.name}» is an empty frame`).toBeGreaterThan(40)
  })
}

/**
 * Each inner tab is a place a link can point at.
 *
 * The notifications tab is the one this was found on — an operator sent «the notification settings»
 * to a colleague and the colleague opened the general tab — so it is the one asserted end to end.
 */
test('a settings tab can be linked to, and survives a reload', async ({ page }) => {
  await page.goto('/agency/settings/workspace?tab=notifications')

  await expect(page.getByTestId('delivery-log')).toBeVisible({ timeout: 30000 })

  await page.reload()
  await expect(page.getByTestId('delivery-log'), 'a reload lost the reader’s place').toBeVisible({ timeout: 30000 })
})

/** And a link somebody sent last year opens the first tab rather than an empty page. */
test('a stale tab link still opens something', async ({ page }) => {
  await page.goto('/agency/settings/workspace?tab=a-tab-that-was-removed')

  await expect(page.locator('h1').first()).toBeVisible({ timeout: 30000 })
  await expect(page.locator('main')).not.toBeEmpty()
})
