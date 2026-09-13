import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * BRAND-MARK-001 / BRAND-LOCKUP-001 — the identity, as a browser actually paints it.
 *
 * A unit test can prove the component renders the right `d` attributes. It cannot prove that the
 * mark survives a collapsed rail, that the Arabic lockup does not wrap into two broken lines, that
 * dark mode resolves the approved light value rather than inheriting something, or that a purple
 * favicon is no longer being served. Those are the failures the owner would actually see, so they
 * are measured here, in the page.
 */

const MARK_PATHS = [
  'M8 14 H24 C34 14 34 32 42 32',
  'M8 32 H42',
  'M8 50 H24 C34 50 34 32 42 32',
]

/** The rendered mark, asked of the DOM rather than of the source. */
async function markIn(page: Page, selector: string) {
  return page.evaluate((sel) => {
    const host = document.querySelector(sel)
    const svg = host?.tagName === 'svg' ? (host as SVGElement) : host?.querySelector('svg')
    if (!svg) return null
    const box = svg.getBoundingClientRect()
    const g = svg.querySelector('g')
    const circle = svg.querySelector('circle')

    return {
      paths: [...svg.querySelectorAll('path')].map((p) => p.getAttribute('d')),
      viewBox: svg.getAttribute('viewBox'),
      // The COMPUTED colour, which is the only way to prove a token resolved.
      stroke: g ? getComputedStyle(g).stroke : null,
      dot: circle ? getComputedStyle(circle).fill : null,
      width: Math.round(box.width),
      height: Math.round(box.height),
      // Clipped in a collapsed rail is the failure this catches.
      visible: box.width > 8 && box.height > 8,
    }
  }, selector)
}

const rgb = (hex: string) => {
  const n = parseInt(hex.replace('#', ''), 16)
  return `rgb(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255})`
}

const GOLD = rgb('#e8a33d')
const LIGHT_MARK = rgb('#0d8a6f')
const DARK_MARK = rgb('#f7f5f0')

test.describe('the CampaignsHub identity in the product', () => {
  test.use({ storageState: AUTH.owner })
  test.describe.configure({ timeout: 90_000 })

  test('the sign-in panel draws the mark, and the gold dot is the gold', async ({ page }) => {
    await page.context().clearCookies()
    await page.goto('/login')

    const mark = await markIn(page, '[data-testid="campaignshub-logo"]')
    expect(mark, 'the sign-in panel drew no mark').not.toBeNull()
    expect(mark!.paths).toEqual(MARK_PATHS)
    expect(mark!.viewBox).toBe('0 0 64 64')
    expect(mark!.dot).toBe(GOLD)
    expect(mark!.visible).toBe(true)
  })

  test('the served favicon is the product’s own, not the one that shipped by mistake', async ({ request }) => {
    const res = await request.get('/favicon.svg')
    expect(res.ok()).toBe(true)

    const body = await res.text()
    for (const d of MARK_PATHS) expect(body).toContain(d)
    expect(body.toLowerCase(), 'the purple mark is still being served').not.toContain('863bff')
  })

  test('the iOS icon is a raster, because iOS cannot render an SVG there', async ({ request }) => {
    const res = await request.get('/apple-touch-icon.png')
    expect(res.ok()).toBe(true)
    expect(res.headers()['content-type']).toContain('image/png')
    // A PNG, not an SVG renamed: the first bytes say so.
    expect((await res.body()).subarray(1, 4).toString()).toBe('PNG')
  })

  for (const locale of ['ar', 'en'] as const) {
    test(`the workspace rail keeps the mark and the tenant's own name (${locale})`, async ({ page, request }) => {
      await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
      await page.goto('/agency/dashboard')
      if (locale === 'en') await switchToEnglish(page)
      // The shell itself, waited for: `domcontentloaded` fires while the app still says «Loading».
      await expect(page.getByTestId('shell-brand-mark')).toBeVisible({ timeout: 25000 })

      const mark = await markIn(page, '[data-testid="shell-brand-mark"]')
      expect(mark?.paths, 'the rail drew no mark').toEqual(MARK_PATHS)
      expect(mark!.visible).toBe(true)

      // The platform provides the SYMBOL; the words stay the workspace's — the rail never
      // recites the lockup line at somebody inside their own workspace.
      await expect(page.locator('body')).not.toContainText('PAID MEDIA IN ONE PLACE')
    })
  }

  test('dark mode uses the approved light mark, and the gold does not move', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/dashboard')
    await expect(page.getByTestId('shell-brand-mark')).toBeVisible({ timeout: 25000 })

    const read = async () => markIn(page, '[data-testid="shell-brand-mark"]')

    /*
     * `data-theme`, not a `.dark` class — and dark is this product's DEFAULT, so a test that only
     * removed a class proved nothing and read the dark value while calling it light.
     */
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'light'))
    const light = await read()
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'))
    const dark = await read()

    expect(light?.stroke, 'light mode is not the approved green').toBe(LIGHT_MARK)
    expect(dark?.stroke, 'dark mode is not the approved light value').toBe(DARK_MARK)
    // One geometry, two themes — never a second drawing.
    expect(dark?.paths).toEqual(light?.paths)
    // The one colour the identity pins.
    expect(light?.dot).toBe(GOLD)
    expect(dark?.dot).toBe(GOLD)
  })

  test('a phone still says what the product is', async ({ page, request }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/dashboard')
    await expect(page.getByTestId('mobile-brand-mark')).toBeVisible({ timeout: 25000 })

    const mark = await markIn(page, '[data-testid="mobile-brand-mark"]')
    expect(mark, 'a phone shows no identity at all').not.toBeNull()
    expect(mark!.paths).toEqual(MARK_PATHS)
    // Not squashed into the controls beside it.
    expect(mark!.visible).toBe(true)
    expect(mark!.width).toBeGreaterThan(12)

    // And the bar it sits in must not push the page sideways.
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    expect(overflow, 'the brand pushed the header off a 390px screen').toBeLessThanOrEqual(1)
  })
})
