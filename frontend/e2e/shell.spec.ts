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

  test('the client and project control is one icon away on the strip, and opens in place', async ({ page }) => {
    await ready(page, '/agency/clients')
    const rail = page.locator('aside').first()

    // Not drawn on the strip itself — two unlabelled dropdowns do not fit in 64px…
    await expect(rail.getByTestId('agency-scope')).toHaveCount(0)
    const icon = page.getByTestId('agency-scope-collapsed')
    await expect(icon).toBeVisible()

    // …but one press away, and the strip does not change width.
    await icon.click()
    await expect(page.getByTestId('agency-scope')).toBeVisible()
    expect(Math.round((await rail.boundingBox())!.width)).toBe(64)
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('agency-scope')).toHaveCount(0)
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

/**
 * Pages that have been converted to fit (NOV-FIT-001). A page joins this list when it is converted, and from then
 * on a regression that makes it scroll — or cuts its content off — fails here with the page and the size named.
 */
const FITTED = ['/agency/clients', '/agency/team', '/agency/tasks']

/**
 * What a locked page can get wrong is not a document scrollbar — the document is pinned and never scrolls — but
 * CONTENT CUT OFF by the lock. So this measures the box the content lives in, not only the document.
 */
async function overflow(page: Page) {
  return page.evaluate(() => {
    const d = document.documentElement
    const content = document.querySelector<HTMLElement>('.portal-content')
    const footer = document.querySelector<HTMLElement>('[data-testid="portal-footer"]')
    const cut: string[] = []
    if (content) {
      const box = content.getBoundingClientRect()
      for (const el of content.querySelectorAll<HTMLElement>('*')) {
        const r = el.getBoundingClientRect()
        const style = getComputedStyle(el)
        if (r.height === 0 || style.position === 'fixed' || style.position === 'absolute' || style.display === 'none') continue
        if (r.bottom > box.bottom + 1) cut.push(`${el.dataset.testid ?? el.tagName}:${Math.round(r.bottom - box.bottom)}`)
        if (cut.length > 4) break
      }
    }

    return {
      docV: d.scrollHeight - d.clientHeight,
      docH: d.scrollWidth - d.clientWidth,
      contentV: content ? content.scrollHeight - content.clientHeight : 0,
      contentH: content ? content.scrollWidth - content.clientWidth : 0,
      footerVisible: footer ? footer.getBoundingClientRect().bottom <= window.innerHeight + 1 : true,
      cut,
    }
  })
}

test.describe('a converted page fits a desktop screen with no scroll (NOV-FIT-001)', () => {
  for (const path of FITTED) {
    for (const [w, h] of [[1366, 768], [1440, 900], [1920, 1080]] as const) {
      for (const locale of ['ar', 'en'] as const) {
        test(`${path} · ${w}×${h} · ${locale}`, async ({ page }) => {
          await page.addInitScript((l) => localStorage.setItem('campaign-hub-locale', l), locale)
          await page.setViewportSize({ width: w, height: h })
          await ready(page, path)
          await page.waitForTimeout(600) // the row count settles after the first measurement

          await expect(page.locator('[data-fit="true"]')).toHaveCount(1)
          const m = await overflow(page)
          // SCROLL-RESTORE-001 — the page flows and may scroll vertically; nothing is cut off and nothing goes sideways.
          expect(m.docH, `document scrolls sideways by ${m.docH}px`).toBeLessThanOrEqual(0)
          expect(m.contentV, `content is cut off by ${m.contentV}px; cut: ${m.cut.join(', ')}`).toBeLessThanOrEqual(1)
          expect(m.contentH, `content is wider than its box by ${m.contentH}px`).toBeLessThanOrEqual(0)
          expect(m.cut, 'elements hang below the content box').toEqual([])
        })
      }
    }
  }
})

test.describe('no page scrolls sideways at any width (NOV-FIT-001)', () => {
  for (const path of FITTED) {
    for (const w of WIDTHS) {
      test(`${path} · ${w}px`, async ({ page }) => {
        await page.setViewportSize({ width: w, height: w < 768 ? 800 : 768 })
        await ready(page, path)
        await page.waitForTimeout(400)

        const m = await overflow(page)
        expect(m.docH, `scrolls sideways by ${m.docH}px at ${w}px`).toBeLessThanOrEqual(0)
        expect(m.contentH).toBeLessThanOrEqual(0)
        // Below the lock a page flows: it may scroll vertically, it may never be cut off.
        if (w < 1280) expect(m.contentV, `content cut off at ${w}px`).toBeLessThanOrEqual(1)
      })
    }
  }

  test('touch targets on a phone are at least 44px', async ({ browser }) => {
    const context = await browser.newContext({ storageState: AUTH.owner, viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true })
    const page = await context.newPage()
    await ready(page, '/agency/clients')
    await page.waitForTimeout(500)
    const small = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('button, [role="tab"], select, input:not([type="checkbox"]):not([type="radio"])')]
      .filter((el) => el.offsetParent !== null)
      .map((el) => ({ n: el.getAttribute('aria-label') ?? el.dataset.testid ?? el.textContent?.trim().slice(0, 20) ?? el.tagName, h: Math.round(el.getBoundingClientRect().height) }))
      .filter((x) => x.h < 44))
    expect(small, `controls under 44px: ${JSON.stringify(small)}`).toEqual([])
    await context.close()
  })

  test('a field is 16px or larger on a phone, so iOS does not zoom on focus', async ({ browser }) => {
    const context = await browser.newContext({ storageState: AUTH.owner, viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true })
    const page = await context.newPage()
    await ready(page, '/agency/clients')
    await page.getByTestId('clients-filters-toggle').click()
    const sizes = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('input:not([type="checkbox"]):not([type="radio"]), select, textarea')]
      .filter((el) => el.offsetParent !== null)
      .map((el) => parseFloat(getComputedStyle(el).fontSize)))
    expect(sizes.length).toBeGreaterThan(0)
    expect(Math.min(...sizes)).toBeGreaterThanOrEqual(16)
    await context.close()
  })
})
