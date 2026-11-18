import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * RELEASE-INTEGRATION — the merged tree (nov-overhaul, consistency-pass, date-range, currency engine, project lifecycle,
 * notifications v2) walked as one product, at the seven widths the builders each measured alone.
 *
 * For every surface the release touches: it renders (no error boundary, no 404 copy), no horizontal scroll at any width, no
 * element hanging past the viewport's sides, no console error and no page error. The deep checks stay in each builder's
 * own spec (shell, role-dashboard, budgets, integrations, access-center, project-lifecycle, notifications-settings,
 * followup-center, period-picker); this one catches what only the COMBINATION can break: a layer, a route, a shared shell.
 */
const SIZES: Array<[number, number]> = [[360, 740], [390, 844], [430, 932], [768, 1024], [1024, 768], [1366, 768], [1920, 1080]]

type Surface = { name: string; path: string; ready: string; auth: string }
const AGENCY: Surface[] = [
  { name: 'role dashboard', path: '/agency/home', ready: 'main', auth: AUTH.owner },
  { name: 'agency dashboard + period', path: '/agency/dashboard', ready: 'main', auth: AUTH.owner },
  { name: 'budgets', path: '/agency/budgets', ready: 'main', auth: AUTH.owner },
  { name: 'spend limits', path: '/agency/spend-limits', ready: 'main', auth: AUTH.owner },
  { name: 'integrations', path: '/agency/integrations', ready: 'main', auth: AUTH.owner },
  { name: 'clients', path: '/agency/clients', ready: 'main', auth: AUTH.owner },
  { name: 'team / access centre', path: '/agency/team', ready: 'main', auth: AUTH.owner },
  { name: 'projects settings table', path: '/agency/settings/workspace?tab=projects', ready: 'main', auth: AUTH.owner },
  { name: 'notification settings (internal)', path: '/agency/settings/workspace?tab=notifications&section=internal', ready: 'main', auth: AUTH.owner },
  { name: 'notification settings (external)', path: '/agency/settings/workspace?tab=notifications&section=external', ready: 'main', auth: AUTH.owner },
  { name: 'follow-up', path: '/agency/followup', ready: 'main', auth: AUTH.owner },
  { name: 'admin notifications', path: '/admin/notifications', ready: 'main', auth: AUTH.admin },
]

/** Console noise that is not the page's: the browser's own resource-load lines for a deliberately absent favicon/push key. */
const NOISE = [/favicon/i, /Failed to load resource: the server responded with a status of 404 \(Not Found\).*\/sw\.js/i]

async function watch(page: Page): Promise<string[]> {
  const errors: string[] = []
  page.on('console', (m) => {
    if (m.type() === 'error' && !NOISE.some((r) => r.test(m.text()))) errors.push(`console: ${m.text().slice(0, 300)}`)
  })
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message.slice(0, 300)}`))
  // Name the request behind a «Failed to load resource» line, so a failure says WHICH call.
  page.on('response', (r) => {
    if (r.status() >= 400 && r.url().includes('/api/')) errors.push(`response: ${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`)
  })

  return errors
}

async function sideOverflow(page: Page): Promise<{ scroll: number; client: number; hanging: string[] }> {
  return page.evaluate(() => {
    const doc = document.documentElement
    const vw = doc.clientWidth
    const hanging: string[] = []
    for (const el of Array.from(document.querySelectorAll<HTMLElement>('body *'))) {
      const s = getComputedStyle(el)
      if (s.display === 'none' || s.visibility === 'hidden' || s.position === 'fixed') continue
      // A closed <details> keeps its content laid out (Chromium's content-visibility: hidden) but draws none of it.
      const closed = el.closest('details:not([open])')
      if (closed && !el.closest('summary')) continue
      // Content inside a scroller of its own (a table strip, a chip row) may extend inside that scroller.
      let p: HTMLElement | null = el.parentElement
      let scrolled = false
      while (p && p !== document.body) {
        const ps = getComputedStyle(p)
        if (/(auto|scroll|hidden|clip)/.test(ps.overflowX)) { scrolled = true; break }
        p = p.parentElement
      }
      if (scrolled) continue
      const r = el.getBoundingClientRect()
      if (r.width === 0 || r.height === 0) continue
      if (r.right > vw + 1 || r.left < -1) {
        hanging.push(`${el.tagName.toLowerCase()}${el.dataset.testid ? `[data-testid=${el.dataset.testid}]` : ''} ${Math.round(r.left)}..${Math.round(r.right)}`)
        if (hanging.length >= 5) break
      }
    }

    return { scroll: doc.scrollWidth, client: vw, hanging }
  })
}

for (const s of AGENCY) {
  test.describe(s.name, () => {
    test.use({ storageState: s.auth })

    for (const [w, h] of SIZES) {
      test(`${w}x${h}`, async ({ page }) => {
        test.setTimeout(90_000)
        await page.setViewportSize({ width: w, height: h })
        const errors = await watch(page)
        const res = await page.goto(s.path)
        expect(res?.status() ?? 200, 'the page itself loads').toBeLessThan(400)
        await expect(page.locator(s.ready).first()).toBeVisible({ timeout: 30_000 })
        await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => undefined)
        await expect(page.getByText(/Something went wrong|حدث خطأ غير متوقع|الصفحة غير موجودة|Page not found/)).toHaveCount(0)

        const o = await sideOverflow(page)
        expect(o.scroll, `no horizontal scroll at ${w}px (scrollWidth ${o.scroll} > ${o.client})`).toBeLessThanOrEqual(o.client + 1)
        expect(o.hanging, `nothing hangs past the viewport at ${w}px`).toEqual([])
        expect(errors, 'no console or page error').toEqual([])
      })
    }
  })
}

test.describe('notification centre and modal layer', () => {
  test.use({ storageState: AUTH.owner })

  for (const [w, h] of [[390, 844], [1366, 768]] as Array<[number, number]>) {
    test(`the bell drawer opens above the shell at ${w}px`, async ({ page }) => {
      await page.setViewportSize({ width: w, height: h })
      const errors = await watch(page)
      await page.goto('/agency/home')
      await expect(page.locator('main').first()).toBeVisible({ timeout: 30_000 })
      const bell = page.getByRole('button', { name: /^(الإشعارات|Notifications)$/ }).first()
      await expect(bell).toBeVisible()
      await bell.click()
      const drawer = page.getByTestId('notification-drawer')
      await expect(drawer).toBeVisible()
      // The drawer is the top layer where it sits: the element at its centre belongs to it, not to the tab bar or header.
      const covered = await drawer.evaluate((el) => {
        const r = el.getBoundingClientRect()
        const panel = Array.from(el.querySelectorAll<HTMLElement>('*')).find((c) => c.getBoundingClientRect().width > 200) ?? el
        const pr = panel.getBoundingClientRect()
        const x = Math.round(pr.left + pr.width / 2)
        const y = Math.round(Math.min(pr.bottom, r.bottom) - 20)
        const top = document.elementFromPoint(x, y)

        return top !== null && !el.contains(top) ? top.outerHTML.slice(0, 160) : null
      })
      expect(covered, 'nothing from the shell covers the drawer').toBeNull()
      await page.keyboard.press('Escape')
      await expect(drawer).toBeHidden()
      expect(errors).toEqual([])
    })
  }
})

test.describe('menus stay on a phone screen', () => {
  test.use({ storageState: AUTH.owner })

  test('the budgets export menu opens inside a 360px screen', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 740 })
    await page.goto('/agency/budgets')
    await page.getByTestId('budgets-export').click()
    const menu = await page.locator('[data-testid="budgets-export-menu"] > div').boundingBox()
    expect(menu).not.toBeNull()
    expect(menu!.x, 'the menu starts on the screen').toBeGreaterThanOrEqual(0)
    expect(menu!.x + menu!.width, 'the menu ends on the screen').toBeLessThanOrEqual(360)
  })
})
