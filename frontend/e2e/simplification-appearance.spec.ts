import { expect, test } from '@playwright/test'
import { AUTH, openFilters } from './helpers'

/**
 * The simplified pages, in light and dark, in Arabic and English, on a phone and on a desktop.
 *
 * `responsive-sweep.spec.ts` already does this — but only for the four portal LANDING pages. Every
 * page the simplification pass actually changed was outside it, which is how a 17px sideways scroll
 * on `/agency/clients` survived: the sweep never opened that page, and the check that did open it
 * measured before the clients had loaded and found a page that was still exactly one viewport wide.
 *
 * So this covers what changed, and it covers it with the data present:
 *
 * - **the page has loaded** before anything is measured — `networkidle`, not "the toolbar appeared";
 * - **no sideways scroll**, which on a phone is content the reader cannot reach and will not know is
 *   there;
 * - **the visible filter bar** measured at the narrowest width, because a row of inline controls is
 *   what UX-FILTERS-001 put back on these pages and therefore the thing most likely to burst a small
 *   screen;
 * - **a filter's own label stays legible** in both themes — a control rendered in a colour that
 *   vanishes against the dark surface is a control nobody can find, which is worse than folding it.
 *
 * Both directions are reached by toggling, which is how a person reaches them too.
 */

/** 343px is the narrowest width in the brief; 1440 is the desk it is designed on. */
const WIDTHS = [
  { name: 'phone', width: 343, height: 760 },
  { name: 'desktop', width: 1440, height: 900 },
] as const

type Page = import('@playwright/test').Page

/** Walk light↔dark and rtl↔ltr, running `check` in each of the four combinations. */
async function eachAppearance(page: Page, check: (where: string) => Promise<void>) {
  for (const step of ['start', 'theme', 'language', 'theme-again'] as const) {
    if (step === 'theme' || step === 'theme-again') {
      await page.getByRole('button', { name: 'Toggle theme' }).first().click()
    }
    if (step === 'language') {
      await page.getByRole('button', { name: 'Toggle language' }).first().click()
    }

    const dir = await page.locator('html').getAttribute('dir')
    const theme = await page.locator('html').getAttribute('data-theme')
    await check(`${dir} · ${theme}`)
  }
}

const PAGES = [
  { id: 'clients', path: '/agency/clients', storage: AUTH.owner },
  { id: 'tasks', path: '/agency/tasks', storage: AUTH.owner },
  { id: 'alerts', path: '/agency/alerts', storage: AUTH.owner },
  { id: 'content', path: '/agency/content', storage: AUTH.owner },
  { id: 'reports', path: '/app/reports', storage: AUTH.advertiser },
  { id: 'files', path: '/app/files', storage: AUTH.advertiser },
] as const

for (const p of PAGES) {
  test.describe(`${p.path} holds together in every appearance`, () => {
    test.use({ storageState: p.storage })

    test('phone and desktop, light and dark, Arabic and English', async ({ page }) => {
      test.setTimeout(90_000)

      for (const vp of WIDTHS) {
        await page.setViewportSize({ width: vp.width, height: vp.height })
        await page.goto(p.path)

        await expect(page.getByTestId(`${p.id}-filters`)).toBeVisible({ timeout: 20000 })
        // Measure the page that the reader gets, not the skeleton that precedes it.
        await page.waitForLoadState('networkidle')

        await eachAppearance(page, async (appearance) => {
          const where = `${p.path} · ${vp.name} · ${appearance}`

          await expect(page.locator('main'), `${where} rendered nothing`).toBeVisible()
          await expect
            .poll(async () => (await page.locator('main').innerText()).trim().length, { timeout: 20000 })
            .toBeGreaterThan(40)

          expect(
            await page.evaluate(
              () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            ),
            `${where} scrolls sideways`,
          ).toBe(false)

          /*
           * A filter's own label must be READABLE, not merely present.
           *
           * These labels are what make an inline control findable — «المنصة», «الحالة», «الأولوية».
           * A theme that leaves one the same colour as the surface behind it hides the control the
           * whole unit exists to expose, and a DOM assertion alone would never notice.
           */
          /*
           * The first VISIBLE one. The bar now opens with a phone-only fold toggle whose own span is
           * first in the DOM and hidden from `sm` up, so an unqualified `.first()` asks a desktop
           * layout to prove that a control it deliberately hides is readable.
           */
          /*
           * A FILTER's label, which means the controls have to be open.
           *
           * Below `sm` the bar folds behind a summary (MOBILE-FILTERS-001), so on a phone the first
           * visible thing inside it is the fold control rather than a filter — and its own count
           * badge is a tinted pill, which is not a label and whose contrast is not this claim. The
           * check opens the fold, exactly as a reader does, and then reads the first visible element
           * that actually says something: the coloured dot on a platform chip is a visible span with
           * no text, and «contrast» for a dot is text-on-brand — about 1.5:1, and meaningless.
           */
          await openFilters(page, p.id)
          const label = page
            .getByTestId(`${p.id}-filters-controls`)
            .locator('label:visible, span:visible')
            .filter({ hasText: /\S/ })
            .first()
          await expect(label, `${where} has no filter label`).toBeVisible()

          const contrast = await label.evaluate((el) => {
            const rgb = (s: string) => (s.match(/[\d.]+/g) ?? []).slice(0, 3).map(Number)
            const lum = ([r, g, b]: number[]) => {
              const c = [r, g, b].map((v) => {
                const x = v / 255
                return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4
              })
              return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]
            }
            // Walk up for the first ancestor that actually paints a background.
            let node: HTMLElement | null = el
            let bg = 'rgba(0, 0, 0, 0)'
            while (node) {
              const c = getComputedStyle(node).backgroundColor
              if (c && !c.startsWith('rgba(0, 0, 0, 0)')) { bg = c; break }
              node = node.parentElement
            }
            const a = lum(rgb(getComputedStyle(el).color))
            const b = lum(rgb(bg))
            return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
          })

          // 3:1 is the WCAG floor for large text and a generous one for this size — anything below it
          // is not a matter of taste, it is text nobody can read.
          expect(contrast, `${where}: a filter label is unreadable against its background`)
            .toBeGreaterThan(3)
        })

        /*
         * And once more with «More filters» open, at the narrow width only — where a page still has
         * one. Four of these six now fold nothing at all, which is the point of the unit; a modal
         * that fits at 1440 tells you nothing, and the phone is where a dialog bursts a page.
         */
        const more = page.getByTestId(`${p.id}-more-filters`)
        if (vp.name === 'phone' && (await more.count()) > 0) {
          // The bar folds on a phone (MOBILE-FILTERS-001), and «More filters» is one of the controls
          // behind the fold — a reader opens it the same way.
          await openFilters(page, p.id)
          await more.click()
          await expect(page.getByRole('dialog')).toBeVisible()
          expect(
            await page.evaluate(
              () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
            ),
            `${p.path}: the filters dialog pushes the page sideways on a phone`,
          ).toBe(false)
          await page.keyboard.press('Escape')
        }
      }
    })
  })
}
