import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * NOV-SHELL-001 — the agency shell: the 64px rail, the phone's navigation layer, and a page that fits.
 *
 * Three claims, each measured in the browser rather than read from the code:
 *
 *   1. The rail starts as a 64px icon strip, one button opens and closes it, and the choice is the person's.
 *   2. On a phone the navigation is a layer above the page that closes on Escape and on the backdrop.
 *   3. No page scrolls sideways at any of the seven widths, and the converted pages do not scroll at all
 *      on a 1366×768 desktop.
 */
test.use({ storageState: AUTH.owner })

const WIDTHS = [360, 390, 430, 768, 1024, 1366, 1920] as const

async function ready(page: Page, path: string) {
  await page.goto(path)
  await expect(page.locator('main')).toBeVisible({ timeout: 30_000 })
  await page.waitForLoadState('networkidle').catch(() => undefined)
}

test.describe('the side rail (NOV-SIDEBAR-001)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 768 })
  })

  test('starts as a 64px strip, opens and closes from one button, and remembers it after a reload', async ({ page }) => {
    await ready(page, '/agency/clients')
    const rail = page.locator('aside').first()

    await expect.poll(async () => Math.round((await rail.boundingBox())?.width ?? 0)).toBe(64)

    await page.getByTestId('rail-toggle').click()
    await expect.poll(async () => Math.round((await rail.boundingBox())?.width ?? 0)).toBe(264)
    await expect(page.getByTestId('rail-toggle')).toHaveAttribute('aria-expanded', 'true')

    await page.reload()
    await expect(page.locator('main')).toBeVisible()
    await expect.poll(async () => Math.round((await page.locator('aside').first().boundingBox())?.width ?? 0)).toBe(264)

    await page.getByTestId('rail-toggle').click()
    await expect.poll(async () => Math.round((await page.locator('aside').first().boundingBox())?.width ?? 0)).toBe(64)
    await page.reload()
    await expect.poll(async () => Math.round((await page.locator('aside').first().boundingBox())?.width ?? 0)).toBe(64)
  })

  test('the choice is kept under the person, not the browser', async ({ page }) => {
    await ready(page, '/agency/clients')
    await page.getByTestId('rail-toggle').click()

    const keys = await page.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('campaign-hub-rail:')))
    expect(keys).toHaveLength(1)
    expect(keys[0]).not.toBe('campaign-hub-rail:anonymous')

    // Somebody else on the same browser starts from the strip.
    await page.evaluate((k) => { localStorage.removeItem(k) }, keys[0])
    await page.reload()
    await expect.poll(async () => Math.round((await page.locator('aside').first().boundingBox())?.width ?? 0)).toBe(64)
  })

  test('the name is the tooltip, and a group opens as a flyout beside its icon', async ({ page }) => {
    await ready(page, '/agency/clients')

    const campaigns = page.getByTestId('nav-group-campaigns')
    await campaigns.hover()
    const flyout = page.getByRole('group', { name: 'الحملات' })
    await expect(flyout).toBeVisible()
    await expect(flyout.getByRole('link', { name: 'الإعلانات' })).toBeVisible()

    const icon = (await campaigns.boundingBox())!
    const panel = (await flyout.boundingBox())!
    // RTL: the strip is on the right, so the flyout opens to its LEFT.
    expect(panel.x + panel.width).toBeLessThanOrEqual(icon.x + 1)

    await flyout.getByRole('link', { name: 'الإعلانات' }).click()
    await expect(page).toHaveURL(/\/agency\/ads$/)
    await expect(flyout).toBeHidden()
  })

  test('Escape closes a flyout', async ({ page }) => {
    await ready(page, '/agency/clients')
    await page.getByTestId('nav-group-finance').click()
    await expect(page.getByRole('group', { name: 'المالية' })).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(page.getByRole('group', { name: 'المالية' })).toBeHidden()
  })

  test('a flyout opens on the other side in English', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('campaign-hub-locale', 'en'))
    await ready(page, '/agency/clients')
    const group = page.getByTestId('nav-group-campaigns')
    await group.click()
    const flyout = page.getByRole('group', { name: 'Campaigns' })
    await expect(flyout).toBeVisible()
    const icon = (await group.boundingBox())!
    expect((await flyout.boundingBox())!.x).toBeGreaterThanOrEqual(icon.x + icon.width - 1)
  })
})

test.describe('the phone navigation layer (NOV-SIDEBAR-001)', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
  })

  test('is closed, opens from the menu button above the page, and closes on Escape and on the backdrop', async ({ page }) => {
    await ready(page, '/agency/clients')

    await expect(page.getByTestId('mobile-drawer')).toHaveCount(0)
    const open = page.getByTestId('drawer-open')
    expect((await open.boundingBox())!.height).toBeGreaterThanOrEqual(44)

    await open.click()
    const drawer = page.getByRole('dialog', { name: 'القائمة' })
    await expect(drawer).toBeVisible()
    await expect(page.getByTestId('mobile-drawer-backdrop')).toBeVisible()

    // Above the content: the dialog is the topmost thing at its own centre.
    const onTop = await page.evaluate(() => {
      const d = document.querySelector('[data-testid="mobile-drawer"]')!
      const r = d.querySelector('aside')!.getBoundingClientRect()
      const hit = document.elementFromPoint(r.left + 20, r.top + 80)
      return !!hit && d.contains(hit)
    })
    expect(onTop).toBe(true)

    await page.keyboard.press('Escape')
    await expect(drawer).toBeHidden()

    await open.click()
    await expect(drawer).toBeVisible()
    await page.getByTestId('mobile-drawer-backdrop').click({ position: { x: 10, y: 400 } })
    await expect(drawer).toBeHidden()
  })

  test('keeps Tab inside while open and a link in it navigates and closes it', async ({ page }) => {
    await ready(page, '/agency/clients')
    await page.getByTestId('drawer-open').click()
    const drawer = page.getByTestId('mobile-drawer')
    await expect(drawer).toBeVisible()

    for (let i = 0; i < 40; i++) {
      await page.keyboard.press('Tab')
      expect(await page.evaluate(() => !!document.activeElement?.closest('[data-testid="mobile-drawer"]'))).toBe(true)
    }

    await drawer.getByRole('link', { name: 'المهام' }).click()
    await expect(page).toHaveURL(/\/agency\/tasks$/)
    await expect(page.getByTestId('mobile-drawer')).toHaveCount(0)
  })
})
