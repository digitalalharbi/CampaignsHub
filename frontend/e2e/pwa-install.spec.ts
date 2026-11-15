import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * NOTIF-PWA-001 — the installable app, in a real browser against the BUILT app.
 *
 * The gate's config blocks service workers for every other spec (see `playwright.config.ts`); this file turns them back on, because
 * the worker is exactly what it is about. What is asserted here is what a browser would check before offering «ثبّت التطبيق», what
 * the worker may and may not hold, and what a person sees with no connection.
 */
test.use({ serviceWorkers: 'allow', locale: 'ar-SA' })

/** Width and height from a PNG's IHDR chunk. */
function pngSize(bytes: Buffer): { width: number; height: number } | null {
  if (bytes.length < 24 || bytes.readUInt32BE(0) !== 0x89504e47) return null

  return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) }
}

async function workerReady(page: Page): Promise<void> {
  await page.evaluate(async () => { await navigator.serviceWorker.ready })
  // A first load is not yet controlled; one reload lets the active worker answer.
  await page.reload()
  await expect.poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null), { timeout: 20_000 }).toBe(true)
}

test.describe('the manifest and icons', () => {
  test.use({ storageState: { cookies: [], origins: [] } })

  test('is valid, and every icon is a real PNG of the size it declares', async ({ page, request }) => {
    await page.goto('/login')
    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', '/manifest.webmanifest')

    const res = await request.get('/manifest.webmanifest')
    expect(res.ok()).toBeTruthy()
    const m = await res.json()
    expect(m).toMatchObject({ name: 'كمبينز هب', lang: 'ar', dir: 'rtl', display: 'standalone', start_url: '/agency/dashboard' })
    expect(m.theme_color).toMatch(/^#[0-9a-f]{6}$/i)

    const seen = new Set<string>()
    for (const icon of m.icons as Array<{ src: string; sizes: string; type: string; purpose?: string }>) {
      if (icon.type !== 'image/png') continue
      const r = await request.get(icon.src)
      expect(r.ok(), `${icon.src} must exist`).toBeTruthy()
      const [w, h] = icon.sizes.split('x').map(Number)
      expect(pngSize(await r.body()), `${icon.src} must be a ${icon.sizes} PNG`).toEqual({ width: w, height: h })
      seen.add(`${icon.purpose ?? 'any'}:${icon.sizes}`)
    }
    for (const needed of ['any:192x192', 'any:512x512', 'maskable:192x192', 'maskable:512x512']) expect(seen.has(needed), needed).toBe(true)

    const apple = await request.get('/apple-touch-icon.png')
    expect(pngSize(await apple.body())).toEqual({ width: 180, height: 180 })
    await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', '/apple-touch-icon.png')
  })

  test('opening the start URL signed out lands on the sign-in page', async ({ page }) => {
    await page.goto('/agency/dashboard')

    await expect(page).toHaveURL(/\/login/)
  })
})

test.describe('signed in', () => {
  test.use({ storageState: AUTH.owner })

  test('the start URL opens the agency dashboard, and the role home is still reachable', async ({ page }) => {
    await page.goto('/agency/dashboard')
    await expect(page).toHaveURL(/\/agency\/dashboard/)

    /*
     * CI-REPAIR (PR #1071): `/agency` is the server-named landing, and the landing stays the dashboard until the Owner
     * flips it (builder E's deferral) — so `/agency` reaches the dashboard, and the role home is reached at its own
     * address. Both facts this test made are still asserted: the start URL is the dashboard, the role home exists.
     */
    await page.goto('/agency')
    await expect(page).toHaveURL(/\/agency\/dashboard/)
    await page.goto('/agency/home')
    await expect(page).toHaveURL(/\/agency\/home/)
    await expect(page.getByTestId('role-dashboard')).toBeVisible({ timeout: 30_000 })
  })

  test('the worker takes control, and its caches never hold an API or auth response', async ({ page }) => {
    await page.goto('/agency/dashboard')
    await workerReady(page)
    // Use the app so it makes its API calls through a controlled page.
    await page.goto('/agency/home')
    await expect(page.getByRole('button', { name: 'الإشعارات' })).toBeVisible({ timeout: 20_000 })
    await page.waitForTimeout(1500)

    const held = await page.evaluate(async () => {
      const urls: string[] = []
      for (const key of await caches.keys()) {
        const cache = await caches.open(key)
        for (const req of await cache.keys()) urls.push(new URL(req.url).pathname)
      }

      return urls
    })

    expect(held.length, 'the shell is cached').toBeGreaterThan(0)
    expect(held).toContain('/offline.html')
    for (const path of held) {
      expect(path, `${path} must not be cached`).not.toMatch(/^\/(api|sanctum|broadcasting|storage|oauth)\//)
      expect(path).not.toMatch(/\.(json|csv|xlsx|pdf)$/)
    }
  })

  test('with no connection it shows the offline page — chromium', async ({ page, context, browserName }) => {
    test.skip(browserName !== 'chromium', 'Playwright only emulates offline faithfully for a service worker in Chromium; the worker logic itself is unit-tested for every branch.')

    await page.goto('/agency/dashboard')
    await workerReady(page)
    await context.setOffline(true)
    await page.goto('/agency/dashboard')

    await expect(page.getByRole('heading', { name: 'لا يوجد اتصال بالإنترنت' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'حاول مرة ثانية' })).toBeVisible()
    await context.setOffline(false)
  })

  test('Chromium finds nothing wrong with installing it — chromium', async ({ page, context, browserName }) => {
    test.skip(browserName !== 'chromium', 'The installability audit is a Chrome DevTools Protocol call.')

    await page.goto('/login')
    await workerReady(page)
    const cdp = await context.newCDPSession(page)
    const { installabilityErrors } = (await cdp.send('Page.getInstallabilityErrors')) as { installabilityErrors: Array<{ errorId: string }> }

    expect(installabilityErrors.map((e) => e.errorId)).toEqual([])
  })
})
