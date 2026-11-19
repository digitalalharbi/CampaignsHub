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
    expect(m).toMatchObject({ name: 'كمبينز هب', lang: 'ar', dir: 'rtl', display: 'standalone', start_url: '/agency/home' })
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
    await page.goto('/agency/home')

    await expect(page).toHaveURL(/\/login/)
  })
})

test.describe('signed in', () => {
  test.use({ storageState: AUTH.owner })

  test('the start URL opens the role home, the bare portal address lands there, and the analytics board is still reachable', async ({ page }) => {
    // AF1: the role dashboard is the landing (the Owner's order), and the installed app's start URL is that landing.
    await page.goto('/agency/home')
    await expect(page).toHaveURL(/\/agency\/home/)
    await expect(page.getByTestId('role-dashboard')).toBeVisible({ timeout: 30_000 })

    await page.goto('/agency')
    await expect(page).toHaveURL(/\/agency\/home/)

    await page.goto('/agency/analytics')
    await expect(page).toHaveURL(/\/agency\/analytics/)
  })

  test('the worker takes control, and its caches never hold an API or auth response', async ({ page }) => {
    await page.goto('/agency/analytics')
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

  /*
   * The title keeps « — chromium» because the gate matches tests by name; it now RUNS in every browser (CI PR #1071).
   *
   * Chromium: the real thing — offline emulation reaches the service worker, the navigation fails, the worker answers it.
   * Firefox and WebKit: Playwright's offline emulation and `page.route` do not reach a service worker's own fetches there,
   * so a failed navigation cannot be produced faithfully. What CAN be asserted in the real browser is everything the
   * worker's offline branch depends on: the worker is active and controls the page, `/offline.html` is precached in the
   * worker's cache, and the cached page is the one a person must see (heading + retry button, rendered). The branch
   * decision itself — network failed → that cached page, never a half-loaded app — is the worker's `navigate()`, covered
   * for every branch in `src/app/sw.test.ts` («opens offline to the offline page…»).
   */
  test('with no connection it shows the offline page — chromium', async ({ page, context, browserName }) => {
    await page.goto('/agency/analytics')
    await workerReady(page)

    if (browserName === 'chromium') {
      await context.setOffline(true)
      await page.goto('/agency/analytics')

      await expect(page.getByRole('heading', { name: 'لا يوجد اتصال بالإنترنت' })).toBeVisible()
      await expect(page.getByRole('button', { name: 'حاول مرة ثانية' })).toBeVisible()
      await context.setOffline(false)

      return
    }

    const worker = await page.evaluate(async () => {
      const reg = await navigator.serviceWorker.getRegistration()
      return { active: reg?.active?.state ?? null, controlled: navigator.serviceWorker.controller !== null }
    })
    expect(worker).toEqual({ active: 'activated', controlled: true })

    // Precached by the worker, as an HTML document — what its offline branch answers with.
    const cached = await page.evaluate(async () => {
      const hit = await caches.match('/offline.html')
      return hit ? { status: hit.status, type: hit.headers.get('content-type') ?? '', html: await hit.text() } : null
    })
    expect(cached, '/offline.html is not in the worker\'s cache').not.toBeNull()
    expect(cached!.status).toBe(200)
    expect(cached!.type).toMatch(/text\/html/)

    // And it is the page a person must see: rendered from the cached bytes, it says so and offers a retry.
    await page.setContent(cached!.html)
    await expect(page.getByRole('heading', { name: 'لا يوجد اتصال بالإنترنت' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'حاول مرة ثانية' })).toBeVisible()
  })

  /*
   * The title keeps « — chromium» because the gate matches tests by name; it now RUNS in every browser (CI PR #1071).
   * Chromium asks the browser's own installability audit (a CDP call). Firefox and WebKit have no such audit, so the same
   * criteria are checked from the page itself: the manifest the page links, its identity and display, start_url inside
   * scope, the 192/512 icons (any + maskable) served as PNG, the colours and language, an active service worker
   * controlling the page, and the apple-touch-icon Safari installs from.
   */
  test('Chromium finds nothing wrong with installing it — chromium', async ({ page, context, browserName }) => {
    await page.goto('/login')
    await workerReady(page)

    if (browserName === 'chromium') {
      const cdp = await context.newCDPSession(page)
      const { installabilityErrors } = (await cdp.send('Page.getInstallabilityErrors')) as { installabilityErrors: Array<{ errorId: string }> }

      expect(installabilityErrors.map((e) => e.errorId)).toEqual([])

      return
    }

    const audit = await page.evaluate(async () => {
      const link = document.querySelector<HTMLLinkElement>('link[rel="manifest"]')
      if (!link) throw new Error('the page links no manifest')
      const res = await fetch(link.href)
      const m = await res.json() as Record<string, unknown> & { icons?: Array<{ src: string; sizes: string; type?: string; purpose?: string }> }
      const scope = new URL(String(m.scope ?? '/'), link.href).href
      const start = new URL(String(m.start_url ?? ''), link.href).href
      const icons: string[] = []
      for (const icon of m.icons ?? []) {
        const r = await fetch(new URL(icon.src, link.href).href)
        if (r.ok && (r.headers.get('content-type') ?? '').includes('image/png')) {
          for (const purpose of String(icon.purpose ?? 'any').split(/\s+/)) icons.push(`${purpose}:${icon.sizes}`)
        }
      }
      const reg = await navigator.serviceWorker.getRegistration()
      const apple = document.querySelector<HTMLLinkElement>('link[rel="apple-touch-icon"]')
      const appleRes = apple ? await fetch(apple.href) : null

      return {
        manifestType: res.headers.get('content-type') ?? '',
        named: typeof m.name === 'string' && m.name !== '' && typeof m.short_name === 'string' && m.short_name !== '',
        display: m.display,
        startInScope: start.startsWith(scope) && new URL(start).origin === location.origin,
        icons,
        theme: m.theme_color, background: m.background_color, lang: m.lang, dir: m.dir,
        worker: reg?.active?.state ?? null,
        controlled: navigator.serviceWorker.controller !== null,
        apple: appleRes ? { ok: appleRes.ok, type: appleRes.headers.get('content-type') ?? '' } : null,
      }
    })

    expect(audit.manifestType).toMatch(/application\/(manifest\+)?json/)
    expect(audit.named, 'name and short_name').toBe(true)
    expect(audit.display).toBe('standalone')
    expect(audit.startInScope, 'start_url inside scope, same origin').toBe(true)
    for (const needed of ['any:192x192', 'any:512x512', 'maskable:192x192', 'maskable:512x512']) expect(audit.icons, needed).toContain(needed)
    expect(audit.theme).toMatch(/^#[0-9a-f]{6}$/i)
    expect(audit.background).toMatch(/^#[0-9a-f]{6}$/i)
    expect({ lang: audit.lang, dir: audit.dir }).toEqual({ lang: 'ar', dir: 'rtl' })
    expect({ worker: audit.worker, controlled: audit.controlled }).toEqual({ worker: 'activated', controlled: true })
    expect(audit.apple).toEqual({ ok: true, type: expect.stringContaining('image/png') })
  })
})
