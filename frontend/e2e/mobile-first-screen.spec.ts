import { expect, test, type Page } from '@playwright/test'

/**
 * MOBILE-HERO-001 — what a phone sees before it scrolls.
 *
 * ## The defect
 *
 * The marketing homepage stacked, on one column: eyebrow, headline, description, support line, four
 * benefits, the chooser's own title and subtitle, three path cards, an includes list — and only THEN
 * «إنشاء حساب / تسجيل الدخول / متابعة طلباتي». About 900px of page before the first decision, so on a
 * 667px phone all three doors were below the fold, and two of them were otherwise reachable only
 * through the hamburger. That is a marketing page that does not ask for the thing it exists to ask
 * for.
 *
 * ## Why this is measured and not eyeballed
 *
 * "Above the fold" is the property most easily lost by accident: a line of copy grows, a font ships
 * a taller fallback, someone adds a badge, and the button slides under the edge with nothing failing.
 * So this asserts the actual geometry at `scrollY = 0` — the element's box is inside the viewport —
 * rather than that it exists in the DOM, which it always did.
 *
 * ## What "visible" means here
 *
 * Present, painted and inside the viewport: non-zero box, not `display:none`/`visibility:hidden`, not
 * transparent, and `bottom <= innerHeight`. `toBeInViewport` alone would accept an element half off
 * the bottom edge, and a half-visible button is not a door somebody will find.
 *
 * Both languages, because Arabic and English set different line counts for the same headline, and
 * both themes, because a theme that changed spacing would be a real regression this would catch.
 */

const PHONES = [
  { name: '375x667', width: 375, height: 667 },
  { name: '390x844', width: 390, height: 844 },
  { name: '430x932', width: 430, height: 932 },
] as const

/** The three doors, plus the headline that has to be readable above them. */
const MUST_BE_IN_FIRST_SCREEN = [
  { id: 'hero-heading', what: 'the value headline' },
  { id: 'hero-primary-cta', what: 'Create account' },
  { id: 'hero-login', what: 'Log in' },
  { id: 'hero-track-requests', what: 'Track my requests' },
] as const

/**
 * Geometry and paint for one test id, read in the page.
 *
 * Returned as data rather than asserted inside `evaluate` so a failure names the pixel it failed by.
 */
async function firstScreen(page: Page, testId: string) {
  return page.evaluate((id) => {
    const el = document.querySelector<HTMLElement>(`[data-testid="${id}"]`)
    if (el === null) return { found: false } as const

    const r = el.getBoundingClientRect()
    const style = getComputedStyle(el)

    return {
      found: true,
      top: Math.round(r.top),
      bottom: Math.round(r.bottom),
      painted: r.width > 0 && r.height > 0 && style.visibility !== 'hidden' && style.display !== 'none' && Number(style.opacity) > 0,
      viewportHeight: window.innerHeight,
      // Sideways spill and the page's own overflow, checked in the same pass.
      overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      right: Math.round(r.right),
      left: Math.round(r.left),
      viewportWidth: document.documentElement.clientWidth,
    } as const
  }, testId)
}

for (const theme of ['light', 'dark'] as const) {
  for (const locale of ['ar', 'en'] as const) {
    test.describe(`the homepage's first screen @responsive · ${locale} · ${theme}`, () => {
      for (const vp of PHONES) {
        test.describe(vp.name, () => {
          test.use({ viewport: { width: vp.width, height: vp.height } })

          test('shows the headline and all three doors without scrolling', async ({ page }) => {
            /*
             * Locale and theme are set BEFORE the app boots, through the same storage key the UI
             * store persists to. Toggling afterwards would re-render and re-measure a page that has
             * already scrolled, and the claim is about what a visitor meets on arrival.
             */
            await page.addInitScript(([l, t]) => {
              // The same two keys `stores/ui.ts` reads at module load — not a persist blob.
              window.localStorage.setItem('campaign-hub-locale', l)
              window.localStorage.setItem('campaign-hub-theme', t)
            }, [locale, theme] as const)

            await page.goto('/')
            await page.waitForLoadState('networkidle').catch(() => undefined)
            // Nothing may scroll the page before the measurement — this is the arrival state.
            await page.evaluate(() => window.scrollTo(0, 0))
            await expect(page.getByTestId('hero-actions')).toBeVisible()

            for (const target of MUST_BE_IN_FIRST_SCREEN) {
              const m = await firstScreen(page, target.id)

              expect(m.found, `${target.what} ([data-testid="${target.id}"]) is not on the page at all`).toBe(true)
              if (!m.found) continue

              expect(m.painted, `${target.what} is in the DOM but not painted`).toBe(true)
              expect(
                m.bottom,
                `${target.what} ends ${m.bottom - m.viewportHeight}px below the fold at ${vp.name} (${locale}/${theme}) — a visitor has to scroll to find it`,
              ).toBeLessThanOrEqual(m.viewportHeight)
              expect(m.top, `${target.what} starts above the viewport`).toBeGreaterThanOrEqual(0)

              // Nothing clipped sideways, and the page itself does not scroll sideways.
              expect(m.overflow, `the page scrolls sideways by ${m.overflow}px at ${vp.name}`).toBeLessThanOrEqual(0)
              expect(m.right, `${target.what} is clipped on the right`).toBeLessThanOrEqual(m.viewportWidth + 1)
              expect(m.left, `${target.what} is clipped on the left`).toBeGreaterThanOrEqual(-1)
            }
          })

          /**
           * The content that moved is still there, and still reachable.
           *
           * The fix works by re-ordering, not by deleting, and that is the half of it a geometry
           * assertion cannot see. If a later change "fixed" the fold by dropping the benefits or the
           * chooser, everything above would still pass and this would not.
           */
          test('nothing was removed to make room', async ({ page }) => {
            await page.goto('/')
            await page.waitForLoadState('networkidle').catch(() => undefined)

            // The chooser and all of its paths.
            await expect(page.getByTestId('hero-path-self-service')).toBeVisible()
            await expect(page.getByTestId('hero-path-multi-client')).toBeVisible()
            await expect(page.getByTestId('hero-path-services')).toBeVisible()
            // The four benefits, and the journey strip below the hero.
            expect(await page.locator('h1 ~ * li, section#usage ul li').count()).toBeGreaterThan(3)
            await expect(page.getByTestId('hero-journey')).toBeVisible()
          })
        })
      }
    })
  }
}
