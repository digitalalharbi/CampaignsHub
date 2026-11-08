import { expect, test, type BrowserContext, type Page } from '@playwright/test'
import { signIn } from './helpers'

/**
 * ENSURE-PORTAL-SESSION-001 (#945) — one browser, two tabs, one person who really holds two tenants in two portals.
 *
 * The account comes from `DemoTwoPortalsSeeder`. Every test starts in a fresh browser context, so a fresh session.
 */
const EMAIL = 'two-portals@demo.campaignshub.io'
const BRAND_PROJECT = 'Two Portals Brand Project'
const AGENCY_PROJECT = 'Two Portals Agency Project'

interface Memberships { current: { id: string; portal: string; tenant: { name: string } } | null; memberships: { id: string; portal: string }[] }

async function api<T>(page: Page, path: string, init: { method?: string; body?: unknown; claim?: string } = {}): Promise<{ status: number; text: string; data: T }> {
  return page.evaluate(async ({ path, init }) => {
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')
    const response = await fetch(`/api/v1${path}`, {
      method: init.method ?? 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf,
        ...(init.claim ? { 'X-Workspace-Membership': init.claim } : {}),
      },
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    })
    const text = await response.text()
    let data: unknown = null
    try { data = JSON.parse(text).data } catch { /* not JSON */ }
    return { status: response.status, text, data: data as never }
  }, { path, init })
}

/** The project's name on the page itself: the project switcher's closed <option> names it too, and is never visible. */
const shown = (page: Page, name: string) => page.getByText(name).locator('visible=true').first()

const memberships = async (page: Page) => (await api<Memberships>(page, '/auth/memberships')).data

/** Signed in and chosen the advertiser: tab A, on its projects page. */
async function tabInBrand(context: BrowserContext): Promise<Page> {
  const page = await context.newPage()
  await page.goto('/login')
  await signIn(page, EMAIL)
  await page.getByTestId('switch-to-app').first().click({ timeout: 30_000 })
  await expect(page).toHaveURL(/\/app(\/|$)/)
  await page.goto('/app/projects')
  await expect(shown(page, BRAND_PROJECT)).toBeVisible({ timeout: 20_000 })

  return page
}

/** The URLs a page asked the API for, from now on. */
function apiCallsOf(page: Page): string[] {
  const seen: string[] = []
  page.on('request', (r) => { if (r.url().includes('/api/v1/')) seen.push(new URL(r.url()).pathname.replace('/api/v1', '')) })

  return seen
}

test.describe('one session, two portals', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('opening the other portal moves nothing: it asks, sends none of its requests, and «stay» goes back', async ({ context }) => {
    const tabA = await tabInBrand(context)
    const before = await memberships(tabA)
    expect(before.current?.tenant.name).toBe('Two Portals Brand')

    const tabB = await context.newPage()
    const calls = apiCallsOf(tabB)
    await tabB.goto('/agency/projects?from=link')

    await expect(tabB.getByTestId('portal-entry-choice')).toBeVisible({ timeout: 20_000 })
    await expect(tabB.getByTestId('portal-entry-current')).toContainText('Two Portals Brand')
    expect(calls.filter((c) => !c.startsWith('/auth/') && !c.startsWith('/me') && !c.startsWith('/public'))).toEqual([])
    expect((await memberships(tabA)).current?.id).toBe(before.current?.id)

    // Tab A did not move, and is not told to.
    expect(tabA.url()).toContain('/app/projects')
    await expect(shown(tabA, BRAND_PROJECT)).toBeVisible()

    await tabB.getByTestId('portal-entry-stay').click()
    await expect(tabB).toHaveURL(/\/app(\/|$)/)
    expect((await memberships(tabB)).current?.id).toBe(before.current?.id)
  })

  test('the click switches: the deep link is kept, the other tab follows, and no brand row or selection survives', async ({ context }) => {
    const tabA = await tabInBrand(context)
    await tabA.evaluate(() => window.localStorage.setItem('campaign-hub-project-storage', JSON.stringify({ state: { currentProjectId: 'of-the-brand' } })))

    const tabB = await context.newPage()
    await tabB.goto('/agency/projects?from=link')
    await tabB.locator('[data-testid^="portal-entry-switch-"]').first().click()

    await expect(tabB).toHaveURL(/\/agency\/projects\?from=link/)
    await expect(shown(tabB, AGENCY_PROJECT)).toBeVisible({ timeout: 20_000 })
    await expect(tabB.getByText(BRAND_PROJECT)).toHaveCount(0)

    // Propagation: the tab left behind is moved, on a full load, to the portal's front door.
    await expect(tabA).toHaveURL(/\/agency(\/|$)/, { timeout: 20_000 })
    await tabA.goto('/agency/projects')
    await expect(shown(tabA, AGENCY_PROJECT)).toBeVisible({ timeout: 20_000 })
    await expect(tabA.getByText(BRAND_PROJECT)).toHaveCount(0)

    expect(await tabB.evaluate(() => window.localStorage.getItem('campaign-hub-project-storage') ?? '')).not.toContain('of-the-brand')
  })

  test('a tab that missed the signal reads nothing of the new tenant, writes nothing, and is taken to choose', async ({ context }) => {
    // This tab never hears `storage`: a blocked signal, as in a browser with storage events unavailable.
    await context.addInitScript(() => {
      if (!window.name.startsWith('deaf')) return
      window.addEventListener('storage', (event) => event.stopImmediatePropagation(), true)
    })

    const tabA = await context.newPage()
    await tabA.goto('/login')
    await tabA.evaluate(() => { window.name = 'deaf' })
    await signIn(tabA, EMAIL)
    await tabA.getByTestId('switch-to-app').first().click({ timeout: 30_000 })
    // Not `waitForURL`: the sign-in redirect replaces its own navigation, and WebKit rejects the wait with
    // «Navigation canceled by policy check» while the page lands exactly where it should. The URL is the claim.
    await expect(tabA).toHaveURL(/\/app(\/|$)/, { timeout: 30_000 })

    const claims: (string | undefined)[] = []
    tabA.on('request', (r) => { if (new URL(r.url()).pathname === '/api/v1/projects') claims.push(r.headers()['x-workspace-membership']) })
    await tabA.goto('/app/projects')
    await expect(shown(tabA, BRAND_PROJECT)).toBeVisible({ timeout: 20_000 })

    const all = await memberships(tabA)
    const brand = all.memberships.find((m) => m.portal === 'app')!
    const agency = all.memberships.find((m) => m.portal === 'agency')!
    await expect.poll(() => claims.at(-1)).toBe(brand.id)

    const projects = (await api<{ id: string; name: string }[]>(tabA, '/projects', { claim: brand.id })).data
    const brandProject = projects.find((p) => p.name === BRAND_PROJECT)!

    // The other tab chooses, explicitly, through the switch.
    const tabB = await context.newPage()
    await tabB.goto('/agency/projects')
    await tabB.getByTestId(`portal-entry-switch-${agency.id}`).click()
    await expect(shown(tabB, AGENCY_PROJECT)).toBeVisible({ timeout: 20_000 })
    expect(tabA.url()).toContain('/app/projects')

    const agencyProject = (await api<{ id: string; name: string }[]>(tabB, '/projects', { claim: agency.id })).data.find((p) => p.name === AGENCY_PROJECT)!

    // Reads and writes at once from both tabs: A is refused every time, B is answered from its own tenant only.
    for (let round = 0; round < 4; round++) {
      const [staleRead, staleWriteAgency, staleWriteBrand, read, write] = await Promise.all([
        api(tabA, '/projects', { claim: brand.id }),
        api(tabA, `/projects/${agencyProject.id}`, { method: 'PATCH', body: { name: 'Stale write' }, claim: brand.id }),
        api(tabA, `/projects/${brandProject.id}`, { method: 'PATCH', body: { name: 'Stale write' }, claim: brand.id }),
        api<{ name: string }[]>(tabB, '/projects', { claim: agency.id }),
        api(tabB, `/projects/${agencyProject.id}`, { method: 'PATCH', body: { name: AGENCY_PROJECT }, claim: agency.id }),
      ])

      for (const refused of [staleRead, staleWriteAgency, staleWriteBrand]) {
        expect(refused.status).toBe(409)
        expect(refused.text).toContain('workspace_changed')
        expect(refused.text).not.toContain(AGENCY_PROJECT)
      }
      expect(read.status).toBe(200)
      expect(read.text).not.toContain(BRAND_PROJECT)
      expect(write.status).toBe(200)
    }

    const names = (await api<{ name: string }[]>(tabB, '/projects', { claim: agency.id })).data.map((p) => p.name)
    expect(names).toContain(AGENCY_PROJECT)
    expect(names).not.toContain('Stale write')

    // Tab A's own client, on its own next request: refused, and it leaves for the chooser saying why.
    await tabA.locator('a[href^="/app/"]:not([href="/app/projects"])').first().click()
    await expect(tabA).toHaveURL(/\/switch\?reason=workspace_changed/, { timeout: 20_000 })
    await expect(tabA.getByTestId('workspace-changed-note')).toBeVisible()
    await expect(tabA.getByText(BRAND_PROJECT)).toHaveCount(0)

    // A full load of the old URL does not put it back: it asks, naming where the session really is.
    await tabA.goto('/app/projects')
    await expect(tabA.getByTestId('portal-entry-choice')).toBeVisible({ timeout: 20_000 })
    await expect(tabA.getByTestId('portal-entry-current')).toContainText('Two Portals Agency')
  })
})
