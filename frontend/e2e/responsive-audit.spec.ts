import { expect, test, type Page } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * MOBILE-001 — every surface, at every size somebody actually holds, with nothing spilling off it.
 *
 * ## Why this is one spec and not a pass over the pages
 *
 * A design pass fixes what somebody looked at. This measures every listed surface at every listed
 * width on every run, so the next component that ships a fixed width, a wide table or an unwrapped
 * button row fails here rather than in somebody's hand. That is the difference between a page that
 * was made responsive once and a product that stays responsive.
 *
 * ## What it actually measures
 *
 * **Horizontal overflow of the DOCUMENT.** Not of every element — a table or a code block is allowed
 * to scroll inside its own container, and forbidding that would push real content off the page or
 * squash it illegibly. What is never allowed is the PAGE scrolling sideways, because that is the
 * thing a reader cannot recover from: the layout has already broken by the time they notice.
 *
 * The offending elements are reported by tag and class when it fails, because "the page is 40px too
 * wide" is not actionable and "this grid is 40px too wide" is.
 *
 * ## The widths
 *
 * 375×667 is the smallest phone still in real use; 390×844 and 430×932 are the two most common
 * modern ones; 768×1024 is the tablet edge where a layout usually switches from stacked to columned
 * and therefore where it most often breaks. Desktop is covered by every other spec in the suite.
 */

const VIEWPORTS = [
  { name: '375x667', width: 375, height: 667 },
  { name: '390x844', width: 390, height: 844 },
  { name: '430x932', width: 430, height: 932 },
  { name: '768x1024', width: 768, height: 1024 },
] as const

/** Public surfaces — no session, reachable by anyone. */
const PUBLIC_ROUTES = [
  '/',
  '/services',
  '/privacy',
  '/terms',
  '/data-deletion',
  '/login',
  '/register',
] as const

/** One authenticated landing surface per portal, with the role that actually holds it. */
const PORTAL_ROUTES = [
  { path: '/admin', state: AUTH.admin },
  { path: '/app/dashboard', state: AUTH.advertiser },
  { path: '/agency', state: AUTH.owner },
  { path: '/portal', state: AUTH.client },
] as const

/**
 * Measure the document's horizontal overflow, and name what caused it.
 *
 * Elements are only reported when they exceed the viewport by more than a pixel — sub-pixel rounding
 * on a rotated or scaled element is not a layout defect and reporting it would train people to
 * ignore this test.
 */
async function overflow(page: Page): Promise<{ scrollWidth: number; clientWidth: number; culprits: string[] }> {
  return page.evaluate(() => {
    const doc = document.documentElement
    const limit = doc.clientWidth

    const culprits = [...document.querySelectorAll<HTMLElement>('body *')]
      .filter((el) => {
        const rect = el.getBoundingClientRect()
        if (rect.width === 0 || rect.height === 0) return false

        // Something that scrolls INSIDE itself is fine; it is the page spilling that is not.
        const style = getComputedStyle(el)
        if (style.overflowX === 'auto' || style.overflowX === 'scroll' || style.overflowX === 'hidden') return false

        return rect.right > limit + 1 || rect.left < -1
      })
      .slice(0, 5)
      .map((el) => {
        const rect = el.getBoundingClientRect()
        const cls = typeof el.className === 'string' ? el.className.slice(0, 70) : ''

        return `${el.tagName.toLowerCase()}.${cls} [${Math.round(rect.left)}..${Math.round(rect.right)}]`
      })

    return { scrollWidth: doc.scrollWidth, clientWidth: doc.clientWidth, culprits }
  })
}

test.describe('every surface fits the screen it is opened on @responsive', () => {
  for (const vp of VIEWPORTS) {
    test.describe(vp.name, () => {
      test.use({ viewport: { width: vp.width, height: vp.height } })

      for (const path of PUBLIC_ROUTES) {
        test(`${path} does not scroll sideways`, async ({ page }) => {
          await page.goto(path)
          // Settle: fonts and any late content can widen a row after first paint.
          await page.waitForLoadState('networkidle').catch(() => undefined)

          const { scrollWidth, clientWidth, culprits } = await overflow(page)

          expect(
            scrollWidth,
            `${path} at ${vp.name} overflows by ${scrollWidth - clientWidth}px. Widest: ${culprits.join(' | ') || 'none identified'}`,
          ).toBeLessThanOrEqual(clientWidth)
        })
      }
    })
  }

  /*
   * The portals, at the two sizes that matter most.
   *
   * Not every width for every portal: these pages are the slowest in the suite to reach, and the
   * failure mode being guarded — a fixed width, a table, a filter row — does not appear between 390
   * and 430 without appearing at 375. The tablet edge is kept because that is where a layout
   * switches from stacked to columned.
   */
  for (const vp of [VIEWPORTS[0], VIEWPORTS[3]]) {
    for (const portal of PORTAL_ROUTES) {
      test.describe(`${portal.path} at ${vp.name}`, () => {
        test.use({ viewport: { width: vp.width, height: vp.height }, storageState: portal.state })

        test('does not scroll sideways', async ({ page }) => {
          await page.goto(portal.path)
          await page.waitForLoadState('networkidle').catch(() => undefined)

          const { scrollWidth, clientWidth, culprits } = await overflow(page)

          expect(
            scrollWidth,
            `${portal.path} at ${vp.name} overflows by ${scrollWidth - clientWidth}px. Widest: ${culprits.join(' | ') || 'none identified'}`,
          ).toBeLessThanOrEqual(clientWidth)
        })
      })
    }
  }
})
