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

/**
 * SHELL-001 — the desktop frame fills the window, and the footer sinks.
 *
 * ## The defect, and how it is measured
 *
 * `<main>` was `flex-1` with block-flow children, so on any page shorter than the viewport the
 * footer sat immediately under the content and everything below it was empty — often more than half
 * the screen, with the copyright line stranded in the middle of a grey band. Four shells carried
 * their own copy of that markup, so it was one defect in four places.
 *
 * A screenshot proves it once. What is measured here is the property behind it: **the distance from
 * the bottom of the footer to the bottom of the document**. On a correct shell that is the footer's
 * own padding and nothing more, whether the page is short (the content box absorbed the slack) or
 * long (the footer follows the content). On the broken shell it was hundreds of pixels, and it grew
 * with the window — which is exactly why it looked worse on a large monitor.
 *
 * `/app/dashboard` at 2560×1440 is the worst case in the product: the widest window, and a page that
 * is short when a fresh workspace has no projects yet. That is the screenshot this came from.
 */
const DESKTOP_VIEWPORTS = [
  { name: '1280x720', width: 1280, height: 720 },
  { name: '1366x768', width: 1366, height: 768 },
  { name: '1440x900', width: 1440, height: 900 },
  { name: '1536x864', width: 1536, height: 864 },
  { name: '1920x1080', width: 1920, height: 1080 },
  { name: '2560x1440', width: 2560, height: 1440 },
] as const

test.describe('the portal frame fills its window @responsive', () => {
  for (const vp of DESKTOP_VIEWPORTS) {
    for (const portal of PORTAL_ROUTES) {
      test.describe(`${portal.path} at ${vp.name}`, () => {
        test.use({ viewport: { width: vp.width, height: vp.height }, storageState: portal.state })

        test('the footer sits at the bottom, with no dead band under it', async ({ page }) => {
          await page.goto(portal.path)
          await page.waitForLoadState('networkidle').catch(() => undefined)

          const m = await page.evaluate(() => {
            const doc = document.documentElement
            const footer = document.querySelector('[data-testid="portal-footer"]')
            if (footer === null) return null

            const rect = footer.getBoundingClientRect()

            return {
              // Document coordinates: the viewport offset has to be added back.
              gapBelowFooter: Math.round(doc.scrollHeight - (rect.bottom + window.scrollY)),
              overflow: doc.scrollWidth - doc.clientWidth,
            }
          })

          expect(m, 'the shell must render its footer').not.toBeNull()
          // 48px covers the footer's own bottom padding at every breakpoint. The defect measured in
          // the hundreds, so this discriminates without being a pixel assertion.
          expect(
            m!.gapBelowFooter,
            `${portal.path} at ${vp.name} leaves ${m!.gapBelowFooter}px of empty page below the footer`,
          ).toBeLessThanOrEqual(48)
          expect(m!.overflow, `${portal.path} at ${vp.name} scrolls sideways`).toBeLessThanOrEqual(0)
        })
      })
    }
  }
})

/**
 * MOBILE-APP-001 — a phone gets an app shell, not the desktop rail made small.
 *
 * Four claims, each the kind that silently stops being true: the desktop rail is really gone, the
 * bottom bar is really there, the bar does not sit on top of the content, and «More» really reaches
 * the sections the bar does not show.
 */
test.describe('the portals are an app on a phone @responsive', () => {
  test.use({ viewport: { width: 390, height: 844 } })

  for (const portal of PORTAL_ROUTES) {
    test.describe(portal.path, () => {
      test.use({ storageState: portal.state })

      test('bottom navigation replaces the desktop rail, and covers nothing', async ({ page }) => {
        await page.goto(portal.path)
        await page.waitForLoadState('networkidle').catch(() => undefined)

        const bar = page.getByTestId('mobile-tab-bar')
        await expect(bar, 'a phone must have the tab bar').toBeVisible()

        // The desktop rail is the thing being replaced. It must not merely be off-screen.
        const railVisible = await page.evaluate(() =>
          [...document.querySelectorAll('aside')].some((el) => {
            const r = el.getBoundingClientRect()

            return r.width > 200 && r.height > 300 && getComputedStyle(el).display !== 'none'
          }))
        expect(railVisible, 'the desktop rail must be gone on a phone').toBe(false)

        /*
         * The bar is `fixed`, so it is out of flow and would sit ON the last rows of the page. The
         * frame pays for it with padding; this is that payment being checked rather than trusted —
         * scroll to the very bottom and assert the footer clears the bar.
         */
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
        const clears = await page.evaluate(() => {
          const footer = document.querySelector('[data-testid="portal-footer"]')
          const tabs = document.querySelector('[data-testid="mobile-tab-bar"]')
          if (footer === null || tabs === null) return null

          return Math.round(tabs.getBoundingClientRect().top - footer.getBoundingClientRect().bottom)
        })
        expect(clears, 'footer and tab bar must both be present').not.toBeNull()
        expect(clears!, `the tab bar covers the footer by ${-clears!}px`).toBeGreaterThanOrEqual(0)
      })

      test('More opens a sheet, and it holds the sections the bar does not', async ({ page }) => {
        await page.goto(portal.path)
        await page.waitForLoadState('networkidle').catch(() => undefined)

        const more = page.getByTestId('mobile-more-toggle')
        await expect(more).toBeVisible()
        await expect(more).toHaveAttribute('aria-expanded', 'false')

        await more.click()
        const sheet = page.getByTestId('mobile-more-sheet')
        await expect(sheet).toBeVisible()
        await expect(more).toHaveAttribute('aria-expanded', 'true')
        // Every portal here has more sections than the bar shows, so an empty sheet is a defect.
        expect(await sheet.getByRole('link').count()).toBeGreaterThan(0)

        // Escape closes it — a sheet you cannot dismiss is a trap on a device with no Escape key,
        // and the backdrop tap is the same handler.
        await page.keyboard.press('Escape')
        await expect(sheet).toHaveCount(0)
      })
    })
  }
})
