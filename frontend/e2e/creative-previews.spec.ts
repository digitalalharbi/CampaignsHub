import { expect, test } from '@playwright/test'
import { API_HEADERS, AUTH, seededProject, selectProject } from './helpers'

/**
 * CREATIVE-PREVIEWS-001 — Owner, 2026-11-07: «حل مشكلة عدم ظهور المعاينة أو صورة الغلاف لمنصة تيك توك وميتا
 * وباقي المنصات! باستثناء سناب تظهر الصور والمعاينات.»
 *
 * Every cached cover is linked as `/media/covers/…` on the SPA's own origin, and only the API serves it. The
 * production edge answered that path with index.html, so every cached cover drew as a broken image. The gate
 * serves the BUILT app through `vite preview` with the same hop map as the dev server, so this proves the
 * whole path in a browser: the payload names our copy, the hop reaches Laravel, the bytes decode, and no
 * Meta or TikTok signed link is ever requested.
 *
 * `DemoCreativeCoversSeeder` gives one demo creative per platform a cover in our store, plus a
 * provider-shaped signed link the browser must not load.
 */
const PROVIDERS = ['meta', 'tiktok', 'snapchat'] as const

test.describe('a content card shows our cached cover for every platform', () => {
  test.use({ storageState: AUTH.owner, viewport: { width: 1440, height: 900 } })

  for (const provider of PROVIDERS) {
    test(`${provider}: the card draws the cover from /media/covers/`, async ({ page, request }) => {
      const projectId = await seededProject(request, 'متجر تجريبي — Demo')
      const library = await (await request.get(`/api/v1/projects/${projectId}/creatives?per_page=100&providers[]=${provider}`, { headers: API_HEADERS })).json()
      const body = JSON.stringify(library)
      expect(body, 'no Meta or TikTok signed link reaches the payload').not.toMatch(/fbcdn\.net|tiktokcdn\.com|ibyteimg\.com/)

      const covered = (library.data?.creatives ?? []).find((c: { preview?: { image_url?: string | null } }) => c.preview?.image_url?.startsWith('/media/covers/'))
      expect(covered, `the seeded ${provider} creative arrives with our copy as its still`).toBeTruthy()

      const signed: string[] = []
      page.on('request', (r) => { if (/fbcdn\.net|tiktokcdn\.com|ibyteimg\.com/.test(r.url())) signed.push(r.url()) })
      const cover = page.waitForResponse((r) => r.url().includes(covered.preview.image_url))

      await selectProject(page, projectId)
      await page.goto(`/agency/content?providers[]=${provider}`)

      const card = page.getByTestId(`content-card-${covered.id}`)
      await card.scrollIntoViewIfNeeded()
      const response = await cover
      expect(response.status()).toBe(200)
      expect(response.headers()['content-type']).toBe('image/png')
      expect(response.headers()['cache-control']).toContain('max-age=604800')

      const img = card.locator(`img[src="${covered.preview.image_url}"]`)
      await expect(img).toHaveAttribute('data-media', 'loaded', { timeout: 15000 })
      expect(await img.evaluate((el: HTMLImageElement) => el.naturalWidth)).toBe(640)
      expect(signed, 'the browser never asked a provider CDN for a still').toEqual([])

      await card.screenshot({ path: `${process.env.SHOTS_DIR ?? 'test-results'}/content-card-${provider}.png` })
    })
  }
})
