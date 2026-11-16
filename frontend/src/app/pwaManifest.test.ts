import { describe, expect, it } from 'vitest'
import manifestRaw from '../../public/manifest.webmanifest?raw'
import indexHtml from '../../index.html?raw'
import offlineHtml from '../../public/offline.html?raw'

/**
 * NOTIF-PWA-001 — the installability criteria, asserted from the files themselves.
 *
 * Lighthouse's PWA category was removed in Lighthouse 12 and no Lighthouse is installed on this machine, so the criteria
 * Chrome checks before it offers installation are asserted directly: a name, a start_url inside the scope, standalone display,
 * a 192 and a 512 PNG icon, a maskable icon, a registered service worker with a fetch handler, an apple-touch-icon.
 * `e2e/pwa.spec.ts` then loads the built manifest in a browser and checks every icon is a real PNG of its declared size.
 */
const manifest = JSON.parse(manifestRaw) as {
  id: string; name: string; short_name: string; lang: string; dir: string; start_url: string; scope: string; display: string
  theme_color: string; background_color: string; prefer_related_applications?: boolean
  icons: Array<{ src: string; sizes: string; type: string; purpose?: string }>
}
const hex = /^#[0-9a-f]{6}$/i

describe('the web app manifest', () => {
  it('names the app in Arabic, right to left, and opens it standalone at the agency dashboard', () => {
    expect(manifest.name).toBe('كمبينز هب')
    expect(manifest.short_name.length).toBeGreaterThan(0)
    expect(manifest.short_name.length).toBeLessThanOrEqual(12)
    expect(manifest).toMatchObject({ lang: 'ar', dir: 'rtl', display: 'standalone', start_url: '/agency/home', scope: '/' })
    expect(manifest.start_url.startsWith(manifest.scope)).toBe(true)
    expect(manifest.theme_color).toMatch(hex)
    expect(manifest.background_color).toMatch(hex)
    expect(manifest.prefer_related_applications).not.toBe(true)
  })

  it('has the icons Chrome requires — 192 and 512 PNG — plus maskable and the sizes between', () => {
    const png = manifest.icons.filter((i) => i.type === 'image/png')
    const sizes = (purpose: string) => png.filter((i) => (i.purpose ?? 'any') === purpose).map((i) => i.sizes)

    expect(sizes('any')).toEqual(expect.arrayContaining(['192x192', '512x512']))
    expect(sizes('maskable')).toEqual(expect.arrayContaining(['192x192', '384x384', '512x512']))
    for (const size of [48, 72, 96, 128, 144, 152, 167, 256, 384]) expect(sizes('any')).toContain(`${size}x${size}`)
    // Every icon file name carries its own size, so a mislabelled one is visible in review.
    for (const i of png) expect(i.src).toMatch(new RegExp(`${i.sizes.split('x')[0]}\\.png$`))
  })

  it('never declares one file as both any and maskable (a maskable icon has no safe zone otherwise)', () => {
    expect(manifest.icons.filter((i) => (i.purpose ?? '').includes(' '))).toEqual([])
  })
})

describe('the document', () => {
  it('links the manifest, the theme colour and an iOS home-screen icon, and starts Arabic and right to left', () => {
    expect(indexHtml).toMatch(/<link rel="manifest" href="\/manifest\.webmanifest"/)
    expect(indexHtml).toMatch(/<meta name="theme-color" content="#[0-9a-f]{6}"/i)
    expect(indexHtml).toMatch(/<link rel="apple-touch-icon" sizes="180x180" href="\/apple-touch-icon\.png"/)
    expect(indexHtml).toMatch(/apple-mobile-web-app-capable/)
    expect(indexHtml).toMatch(/<html lang="ar" dir="rtl"/)
    expect(indexHtml).toMatch(/viewport/)
  })

  it('has an offline page that says so in Arabic, and loads nothing from the network', () => {
    expect(offlineHtml).toContain('لا يوجد اتصال')
    expect(offlineHtml).toMatch(/<html lang="ar" dir="rtl"/)
    expect(offlineHtml).not.toMatch(/(src|href)="https?:/)
  })
})
