import { expect, test, type Browser, type Page } from '@playwright/test'
import { request as pwRequest } from '@playwright/test'
import { AUTH, csrfHeaders, E2E_ORIGIN } from './helpers'

/**
 * ROLE-DASHBOARD-001 — the landing page of each role, in a real browser, at seven widths.
 *
 * For every role: sign in → «الرئيسية» opens that role's own dashboard (the server decides which) →
 *   - no horizontal scroll at any width, and no vertical scroll at all from 1366×768 up;
 *   - no console error and no page error;
 *   - every figure card opens a real page of the product (no 404), and the page behind it is not the home again.
 *
 * The three non-manager roles are real invitations accepted through the product (the owner invites with a category and,
 * for the project manager, one client), so what each member sees is what the access centre actually gives them.
 */
const SIZES: Array<[number, number]> = [[360, 740], [390, 844], [430, 932], [768, 1024], [1024, 768], [1366, 768], [1920, 1080]]

type Role = { key: 'manager' | 'pm' | 'finance' | 'marketer'; category: string | null; withClient: boolean }
const ROLES: Role[] = [
  { key: 'manager', category: null, withClient: false },
  { key: 'pm', category: 'project-manager', withClient: true },
  { key: 'finance', category: 'finance', withClient: false },
  { key: 'marketer', category: 'media-buyer', withClient: true },
]

const stateFile = (r: Role) => `e2e/.auth/role-dashboard-${r.key}.json`

async function accept(browser: Browser, link: string, file: string) {
  const guest = await browser.newContext({ storageState: { cookies: [], origins: [] }, baseURL: E2E_ORIGIN })
  const g = await guest.newPage()
  await g.goto(link)
  await g.getByLabel(/Full name|الاسم الكامل/).fill('Role Member')
  await g.getByLabel(/Password|كلمة المرور/).fill('secret1234')
  const join = g.getByRole('button', { name: /Join workspace|الانضمام/ })
  await expect(join).toBeEnabled()
  await join.click()
  await expect(g).toHaveURL(/\/(dashboard|agency|app|home)/, { timeout: 20_000 })
  await guest.storageState({ path: file })
  await guest.close()
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ browser }) => {
  test.setTimeout(180_000)
  const api = await pwRequest.newContext({ baseURL: E2E_ORIGIN, storageState: AUTH.owner })
  const headers = await csrfHeaders(api)
  const clients = (await (await api.get('/api/v1/client-workspaces', { headers })).json()).data as Array<{ id: string }>
  const stamp = Date.now()
  for (const role of ROLES.filter((r) => r.category !== null)) {
    const res = await api.post('/api/v1/settings/access/invitations', {
      headers,
      data: { email: `dash.${role.key}.${stamp}@example.com`, category: role.category, client_ids: role.withClient ? clients.slice(0, 2).map((c) => c.id) : [] },
    })
    expect(res.status(), await res.text()).toBe(201)
    await accept(browser, (await res.json()).data.dev_link as string, stateFile(role))
  }
  await api.dispose()
})

async function guarded(page: Page) {
  const errors: string[] = []
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`))
  page.on('console', (m) => {
    if (m.type() !== 'error') return
    const text = m.text()
    // A refused request is the server doing its job for a role that lacks a section; the browser logs it as an error.
    if (/status of 40[134]|Failed to load resource/.test(text)) return
    errors.push(text)
  })

  return errors
}

for (const role of ROLES) {
  test.describe(`${role.key}`, () => {
    test(`lands on its own dashboard and fits every width`, async ({ browser }) => {
      test.setTimeout(240_000)
      const context = await browser.newContext({ storageState: role.category ? stateFile(role) : AUTH.owner, baseURL: E2E_ORIGIN })
      const page = await context.newPage()
      const errors = await guarded(page)

      for (const [w, h] of SIZES) {
        await page.setViewportSize({ width: w, height: h })
        await page.goto('/agency/home')
        const root = page.getByTestId('role-dashboard')
        await expect(root, `${role.key} @${w}`).toHaveAttribute('data-view', role.key, { timeout: 30_000 })
        await expect(page.getByTestId('dash-kpis')).toBeVisible()
        await page.waitForTimeout(700)

        const m = await page.evaluate(() => {
          const d = document.documentElement
          const wide = [...document.querySelectorAll<HTMLElement>('main *')].filter((e) => e.getBoundingClientRect().right > window.innerWidth + 1 && e.getBoundingClientRect().width > 0).slice(0, 4).map((e) => e.dataset.testid || e.tagName)
          return { x: d.scrollWidth - d.clientWidth, y: d.scrollHeight - window.innerHeight, wide }
        })
        expect(m.x, `${role.key} @${w}: horizontal overflow by ${m.wide.join(',')}`).toBeLessThanOrEqual(0)
        if (w >= 1366) expect(m.y, `${role.key} @${w}×${h}: the page scrolls vertically by ${m.y}px`).toBeLessThanOrEqual(1)
      }
      expect(errors, errors.join('\n')).toEqual([])
      await context.close()
    })

    test(`every figure card opens a real page`, async ({ browser }) => {
      test.setTimeout(180_000)
      const context = await browser.newContext({ storageState: role.category ? stateFile(role) : AUTH.owner, baseURL: E2E_ORIGIN, viewport: { width: 1366, height: 768 } })
      const page = await context.newPage()
      const errors = await guarded(page)
      await page.goto('/agency/home')
      await expect(page.getByTestId('role-dashboard')).toHaveAttribute('data-view', role.key, { timeout: 30_000 })
      const hrefs = await page.locator('[data-testid="dash-kpis"] a[href]').evaluateAll((els) => [...new Set(els.map((e) => e.getAttribute('href') as string))])
      expect(hrefs.length, 'a dashboard with no figure that opens anything').toBeGreaterThan(0)
      for (const href of hrefs) {
        await page.goto(href)
        await expect(page.getByTestId('not-found'), `${href} is a dead end`).toHaveCount(0)
        await expect(page).not.toHaveURL(/\/agency\/home$/)
        await expect(page.locator('main h1, h1').first()).toBeVisible({ timeout: 30_000 })
      }
      expect(errors, errors.join('\n')).toEqual([])
      await context.close()
    })
  })
}
